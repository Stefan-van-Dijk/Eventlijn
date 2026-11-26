<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../server/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        echo '{"error":"Methode niet toegestaan"}';
        exit;
    }
    $config = new Config();
    $store = new EventStore($config->directory, $_SERVER['DOCUMENT_ROOT'] ?? '');
    $store->transaction(Accounts::REGISTRY, static fn(array $state) => null);
    echo '{"service":"eventlijn","storage":"configured","version":"1.0.0"}';
} catch (Throwable $error) {
    http_response_code(503);
    echo '{"error":"Event Line is niet geconfigureerd of de opslag is niet beschikbaar"}';
}
