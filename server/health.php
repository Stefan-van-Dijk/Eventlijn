<?php
declare(strict_types=1);
require __DIR__.'/EventStore.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function fail(int $code,string $message): never { http_response_code($code); echo json_encode(['error'=>$message]); exit; }
try {
    $dir=getenv('EVENTLIJN_DATA_DIR');
    if (!$dir) throw new RuntimeException('Configuration missing');
    $store=new EventStore($dir,$_SERVER['DOCUMENT_ROOT']);
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        echo json_encode(['service'=>'eventlijn','storage'=>'configured','version'=>'0.1.0']); exit;
    }
    fail(405,'Only health check available in this release');
} catch(Throwable $e) { fail(503,'Storage not configured or unavailable'); }
