<?php
declare(strict_types=1);
require __DIR__.'/../server/EventStore.php';
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Check failed'); }
$base=sys_get_temp_dir().'/eventlijn-test-'.EventStore::id();
mkdir($base,0700); mkdir($base.'/public',0700); mkdir($base.'/private',0700);
try {
    $store=new EventStore($base.'/private',$base.'/public');
    $a=EventStore::id(); $b=EventStore::id();
    $store->transaction($a,function(array &$s){$s['events']['test']=['title'=>'Demo'];});
    check($store->transaction($a,fn($s)=>$s['events']['test']['title'])==='Demo');
    check($store->transaction($b,fn($s)=>$s['events'])===[]);
    try {$store->transaction($a,function(array &$s){$s['events']=[];throw new LogicException('rollback');}); throw new RuntimeException('Expected rollback');} catch(LogicException $e){}
    check(count($store->transaction($a,fn($s)=>$s['events']))===1);
    try {$store->transaction('../escape',fn($s)=>null);throw new RuntimeException('Expected rejection');} catch(InvalidArgumentException $e){}
    try {new EventStore($base.'/public',$base.'/public');throw new LogicException('Expected rejection');} catch(RuntimeException $e){}
    echo "Storage checks passed\n";
} finally {
    foreach(glob($base.'/private/*') as $p) unlink($p);
    rmdir($base.'/private');rmdir($base.'/public');rmdir($base);
}
