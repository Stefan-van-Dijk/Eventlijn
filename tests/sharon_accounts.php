<?php
declare(strict_types=1);
require __DIR__.'/../server/Config.php';
require __DIR__.'/../server/EventStore.php';
require __DIR__.'/../server/MailQueue.php';
require __DIR__.'/../server/Accounts.php';
require __DIR__.'/../server/Sharon.php';
$checks=0;
function check(bool $ok):void {global $checks;$checks++;if(!$ok)throw new RuntimeException('Check '.$checks.' failed');}
function fails(callable $run,int $status):void {
 try{$run();}catch(AppError $error){check($error->getCode()===$status);return;}
 throw new RuntimeException('Expected HTTP '.$status);
}
$base=sys_get_temp_dir().'/eventline-sharon-'.EventStore::id();
mkdir($base,0700);mkdir($base.'/public',0700);mkdir($base.'/private',0700);
try {
 putenv('EVENTLIJN_DATA_DIR='.$base.'/private');putenv('EVENTLIJN_BASE_URL=http://localhost/');
 putenv('EVENTLIJN_MAIL_MODE=file');putenv('EVENTLIJN_MAIL_FROM=eventline@example.test');
 $config=new Config();$store=new EventStore($base.'/private',$base.'/public');$accounts=new Accounts($store,$config);
 $claims=['id'=>'SharonAcct01','personId'=>'LocalUser001','email'=>'member@example.test','name'=>'Persoon'];
 $calls=0;
 fails(fn()=>Sharon::redeemAccount('',str_repeat('a',43),function()use(&$calls){$calls++;return [];}),503);check($calls===0);
 fails(fn()=>Sharon::redeemAccount(str_repeat('k',43),'bad',function()use(&$calls){$calls++;return [];}),403);check($calls===0);
 $received=Sharon::redeemAccount(str_repeat('k',43),str_repeat('a',43),function($url,$body,$key)use($claims){
  check($url==='https://sharon.life/log/api/connections.php');
  check(json_decode($body,true)===['protocol'=>'sharon-network-v1','action'=>'account-app-redeem','app'=>'eventline','code'=>str_repeat('a',43)]);
  check($key===str_repeat('k',43));return ['account'=>$claims];
 });check($received===$claims);
 fails(fn()=>Sharon::redeemAccount(str_repeat('k',43),str_repeat('a',43),fn()=>['error'=>'expired']),403);
 fails(fn()=>$accounts->loginSharon([...$claims,'id'=>'bad']),403);
 fails(fn()=>$accounts->loginSharon([...$claims,'email'=>'bad']),422);
 $login=$accounts->loginSharon([...$claims,'role'=>'employee','org'=>'attacker-chosen']);
 $organization=$store->readExisting($login['org']);$member=$organization['members'][$login['id']];
 check($organization['name']==='Persoonlijk');check($member['status']==='active' && $member['role']==='admin');
 check($member['email']===$claims['email']);check(password_get_info($member['password'])['algoName']!=='unknown');
 check($login===$accounts->loginSharon($claims));
 fails(fn()=>$accounts->loginSharon([...$claims,'personId'=>'OtherUser001']),409);
 fails(fn()=>$accounts->loginSharon([...$claims,'id'=>'AnotherAcc01']),409);
 foreach(['active','pending','rejected','unverified'] as $status){
  $email=$status.'@example.test';
  $org=$accounts->register(['email'=>$email,'name'=>$status,'organization'=>'Bestaande organisatie','password'=>'password-for-fixture']);
  $id=$store->transaction($org,function(array &$state)use($status):string{
   $id=array_key_first($state['members']);$state['members'][$id]['status']=$status;$state['members'][$id]['role']='employee';return $id;
  });
  $userClaims=['id'=>substr(hash('sha256',$status),0,12),'personId'=>substr(hash('sha256',$email),0,12),'email'=>$email,'name'=>$status];
  if(in_array($status,['active','pending'],true)){
   $mapped=$accounts->loginSharon($userClaims);check($mapped['org']===$org&&$mapped['id']===$id);
   $old=$store->readExisting($org)['members'][$id];check($old['status']===$status&&$old['role']==='employee');
  }else fails(fn()=>$accounts->loginSharon($userClaims),403);
 }
 $token=$accounts->createAppSession($login);check(strlen($token)===43);
 check($accounts->readAppSession($token)===$login);
 check(!str_contains(file_get_contents($base.'/private/'.Accounts::REGISTRY.'.json'),$token));
 fails(fn()=>$accounts->readAppSession('bad'),401);
 $accounts->revokeAppSession($token);fails(fn()=>$accounts->readAppSession($token),401);
 $token=$accounts->createAppSession($login);
 $store->transaction(Accounts::REGISTRY,function(array &$state)use($token){$state['appSessions'][hash('sha256',$token)]['until']=time()-1;});
 fails(fn()=>$accounts->readAppSession($token),401);
 $token=$accounts->createAppSession($login);
 $store->transaction($login['org'],function(array &$state)use($login){$state['members'][$login['id']]['sessionVersion']++;});
 fails(fn()=>$accounts->readAppSession($token),401);
 echo json_encode(['ok'=>true,'checks'=>$checks])."\n";
} finally {
 foreach(glob($base.'/private/*') as $file)unlink($file);
 rmdir($base.'/private');rmdir($base.'/public');rmdir($base);
}
