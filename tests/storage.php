<?php
declare(strict_types=1);
require __DIR__.'/../server/EventStore.php';
require __DIR__.'/../server/Workflow.php';
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Check failed'); }
$base=sys_get_temp_dir().'/eventlijn-test-'.EventStore::id();
mkdir($base,0700); mkdir($base.'/public',0700); mkdir($base.'/private',0700);
try {
    $store=new EventStore($base.'/private',$base.'/public');
    $a=EventStore::id(); $b=EventStore::id();
    $store->transaction($a,function(array &$s){$s['events']['test']=['title'=>'Demo'];});
    check($store->transaction($a,fn($s)=>$s['events']['test']['title'])==='Demo');
    check($store->readExisting($a)['events']['test']['title']==='Demo');
    check($store->readExisting($b)===null && !file_exists($base.'/private/'.$b.'.json.lock'));
    check($store->readExisting('../escape')===null);
    $legacyId = EventStore::id();
    $legacy = ['id' => $legacyId, 'title' => 'Ouder evenement', 'start' => '2024-02-29', 'end' => '2024-03-01',
        'kind' => 'external', 'registration' => 'approval', 'location' => '', 'topic' => ''];
    $legacyState = ['events' => [$legacyId => $legacy], 'requests' => []];
    $legacyView = Workflow::view($legacyState, ['status' => 'active'])['events'][0];
    check($legacyView['visibility'] === 'organization' && $legacyView['participantCount'] === 0);
    check(!isset($legacyView['ref']) && $legacyState['events'][$legacyId] === $legacy);
    check(Workflow::publicEvent($legacyState, $legacyId) === null);
    $actor = ['status' => 'active', 'role' => 'admin'];
    Workflow::dispatch('event.visibility', $legacyState, $actor, ['eventId' => $legacyId, 'visibility' => 'public']);
    $public = Workflow::publicEvent($legacyState, $legacyId);
    check(preg_match('/\A[A-Za-z0-9_-]{12}\z/', $public['ref']['id']) === 1);
    check($legacyState['events'][$legacyId]['id'] === $legacyId);
    Workflow::dispatch('event.visibility', $legacyState, $actor, ['eventId' => $legacyId, 'visibility' => 'organization']);
    check(Workflow::publicEvent($legacyState, $legacyId) === null);
    Workflow::dispatch('event.visibility', $legacyState, $actor, ['eventId' => $legacyId, 'visibility' => 'public']);
    check(Workflow::publicEvent($legacyState, $legacyId)['ref'] === $public['ref']);
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
