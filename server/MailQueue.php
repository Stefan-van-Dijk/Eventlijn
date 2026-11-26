<?php
declare(strict_types=1);

final class MailQueue
{
    public static function add(array &$state, string $email, string $subject, string $body): void
    {
        $state['outbox'][EventStore::id()] = [
            'email' => $email, 'subject' => $subject, 'body' => $body,
        ];
    }

    public static function deliver(EventStore $store, Config $config, string $organization): int
    {
        return $store->transaction($organization, static function (array &$state) use ($config): int {
            $remaining = 0;
            foreach ($state['outbox'] ?? [] as $id => $item) {
                if (($item['retryAt'] ?? 0) > time()) {
                    $remaining++;
                    continue;
                }
                if (self::send($config, $id, $item)) {
                    unset($state['outbox'][$id]);
                    continue;
                }
                $attempt = ($item['attempt'] ?? 0) + 1;
                $state['outbox'][$id]['attempt'] = $attempt;
                $state['outbox'][$id]['retryAt'] = time() + min(3600, 60 * (2 ** min($attempt, 6)));
                $remaining++;
            }
            return $remaining;
        });
    }

    private static function send(Config $config, string $id, array $item): bool
    {
        $item['body'] .= "\n\nEvent Line: " . $config->baseUrl;
        if ($config->mailMode === 'file') {
            $dir = $config->directory . '/mail';
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                return false;
            }
            return file_put_contents($dir . '/' . $id . '.json', json_encode($item, JSON_THROW_ON_ERROR), LOCK_EX) !== false;
        }
        return mail($item['email'], '=?UTF-8?B?' . base64_encode($item['subject']) . '?=', $item['body'], [
            'From' => $config->mailFrom,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
