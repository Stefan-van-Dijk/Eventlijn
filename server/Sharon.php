<?php
declare(strict_types=1);

final class Sharon
{
    public static function redeemAccount(string $key, string $code, ?callable $exchange = null): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $key)) {
            throw new AppError('Inloggen met Sharon is nog niet ingesteld.', 503);
        }
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code)) {
            throw new AppError('De Sharon-aanmeldcode is ongeldig of verlopen.', 403);
        }
        $payload = json_encode([
            'protocol' => 'sharon-network-v1', 'action' => 'account-app-redeem',
            'app' => 'eventline', 'code' => $code,
        ], JSON_THROW_ON_ERROR);
        $endpoint = 'https://sharon.life/log/api/connections.php';
        if ($exchange !== null) {
            $result = $exchange($endpoint, $payload, $key);
        } else {
            if (!function_exists('curl_init')) {
                throw new AppError('De verbinding met Sharon is niet beschikbaar.', 503);
            }
            $response = '';
            $oversized = false;
            $request = curl_init($endpoint);
            curl_setopt_array($request, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Sharon-App-Key: ' . $key],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$oversized): int {
                    if (strlen($response) + strlen($chunk) > 8192) {
                        $oversized = true;
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $success = curl_exec($request);
            $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
            curl_close($request);
            if ($success === false || $oversized || $status !== 200) {
                throw new AppError('De Sharon-aanmelding is verlopen. Open Event Line opnieuw vanuit Sharon.', 403);
            }
            $result = json_decode($response, true);
        }
        if (!is_array($result) || !is_array($result['account'] ?? null)) {
            throw new AppError('Sharon kon de aanmelding niet bevestigen.', 403);
        }
        return $result['account'];
    }

    public static function enabled(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        return ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && str_ends_with($parts['path'] ?? '', '/eventlijn.html');
    }

    public static function dispatch(string $url, array $state, array $actor, array $input): array
    {
        if (!self::enabled($url)) {
            throw new AppError('De koppeling met Sharon is niet ingesteld.', 404);
        }

        $id = $input['id'] ?? null;
        $request = is_string($id) ? ($state['requests'][$id] ?? null) : null;
        if (
            ($actor['status'] ?? null) !== 'active'
            || $request === null
            || ($request['userId'] ?? null) !== ($actor['id'] ?? '')
            || !in_array($request['status'] ?? null, ['approved', 'closed'], true)
        ) {
            throw new AppError('Deze deelname is niet beschikbaar voor Sharon.', 404);
        }

        $event = $state['events'][$request['eventId']] ?? null;
        if ($event === null) {
            throw new AppError('Het evenement is niet meer beschikbaar.', 404);
        }

        $fields = [
            'title' => $event['title'],
            'day' => $request['day'],
            'location' => $event['location'] ?? '',
        ];

        return [
            'ok' => true,
            'url' => $url . '#eventlijn=' . rawurlencode(json_encode($fields, JSON_THROW_ON_ERROR)),
        ];
    }
}
