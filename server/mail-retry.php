<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

try {
    $config = new Config();
    $root = $argv[1] ?? '';
    $store = new EventStore($config->directory, $root);
    $organizations = $store->transaction(Accounts::REGISTRY, static fn(array $state): array => array_keys($state['organizations'] ?? []));
    $remaining = 0;
    foreach ($organizations as $id) {
        $remaining += MailQueue::deliver($store, $config, $id);
    }
    echo "Resterende berichten: " . $remaining . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Event Line: e-mailwachtrij niet beschikbaar. Controleer configuratie en rechten.\n");
    exit(1);
}
