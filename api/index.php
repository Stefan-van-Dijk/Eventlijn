<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../server/bootstrap.php';
require_once __DIR__ . '/../server/Workflow.php';
require_once __DIR__ . '/../server/Sharon.php';

function reply(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sessionActor(array $state, array $session): ?array
{
    $actor = $state['members'][$session['id'] ?? ''] ?? null;
    return $actor && $actor['sessionVersion'] === ($session['version'] ?? null)
        && in_array($actor['status'], ['pending', 'active'], true) ? $actor : null;
}

function flushMail(EventStore $store, Config $config, ?string $org): void
{
    if ($org === null) {
        return;
    }
    try {
        MailQueue::deliver($store, $config, $org);
    } catch (Throwable $error) {
        error_log('Event Line: e-mail blijft in de wachtrij (' . get_class($error) . ').');
    }
}

try {
    $config = new Config();
    if (!$config->local && ($_SERVER['HTTPS'] ?? 'off') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1') {
        reply(['error' => 'Gebruik de HTTPS-versie van Event Line.'], 403);
    }
    $store = new EventStore($config->directory, $_SERVER['DOCUMENT_ROOT'] ?? '');
    $accounts = new Accounts($store, $config);
} catch (Throwable $error) {
    reply(['error' => 'Event Line is nog niet geconfigureerd of de opslag is niet beschikbaar.'], 503);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        reply(['error' => 'Deze methode is niet beschikbaar.'], 405);
    }
    if (session_status() !== PHP_SESSION_NONE) {
        throw new RuntimeException('Sessie startte buiten Event Line');
    }
    session_name('EVENTLIJN_SESSION');
    if (!session_start([
        'use_strict_mode' => true,
        'use_only_cookies' => true,
        'use_trans_sid' => false,
        'cookie_path' => $config->cookiePath(),
        'cookie_domain' => '',
        'cookie_secure' => !$config->local,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ])) {
        throw new RuntimeException('Sessie niet beschikbaar');
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $session = $_SESSION['account'] ?? [];
    if (time() - ($session['lastSeen'] ?? 0) > 7200) {
        unset($_SESSION['account']);
        $session = [];
    } elseif ($session) {
        $_SESSION['account']['lastSeen'] = time();
    }
    $scope = $session['org'] ?? null;
    if ($method === 'GET') {
        $view = [
            'csrf' => $_SESSION['csrf'], 'actor' => null, 'organization' => null, 'members' => [],
            'events' => [], 'requests' => [], 'spotlights' => [], 'sharonEnabled' => Sharon::enabled($config->sharonUrl),
        ];
        session_write_close();
        if ($scope !== null) {
            $view = $store->transaction($scope, static function (array $state) use ($view, $session): array {
                $actor = sessionActor($state, $session);
                if (!$actor) {
                    return $view;
                }
                $view['actor'] = Accounts::publicMember($actor);
                $view['organization'] = ['id' => $state['organization'], 'name' => $state['name']];
                if ($actor['status'] === 'active') {
                    $view = array_replace($view, Workflow::view($state, $actor));
                    if ($actor['role'] === 'admin') {
                        $view['members'] = array_values(array_map([Accounts::class, 'publicMember'], $state['members']));
                    }
                }
                return $view;
            });
        }
        reply($view);
    }

    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        throw new AppError('Je sessie is verlopen. Laad de pagina opnieuw.', 403);
    }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0])) !== 'application/json') {
        throw new AppError('Gebruik JSON voor dit verzoek.', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        throw new AppError('Dit verzoek is te groot.', 413);
    }
    $input = json_decode($raw, true, 24);
    if (!is_array($input) || !is_string($input['action'] ?? null)) {
        throw new AppError('Ongeldig verzoek.', 400);
    }
    $action = $input['action'];
    $result = [];
    $deliver = null;
    if (str_starts_with($action, 'auth.')) {
        if ($action === 'auth.logout') {
            $_SESSION = [];
            session_regenerate_id(true);
        } elseif ($action === 'auth.login') {
            $accounts->rateLimit($action, 25, 900);
            $account = $accounts->login($input);
            session_regenerate_id(true);
            $_SESSION = ['account' => $account + ['lastSeen' => time()], 'csrf' => bin2hex(random_bytes(32))];
        } else {
            if (!in_array($action, ['auth.register', 'auth.verify', 'auth.forgot', 'auth.reset'], true)) {
                throw new AppError('Onbekende actie.', 404);
            }
            $accounts->rateLimit($action, 15, 900);
            $deliver = match ($action) {
                'auth.register' => $accounts->register($input),
                'auth.verify' => $accounts->verify(Accounts::text($input, 'token', 100)),
                'auth.forgot' => $accounts->forgot($input),
                'auth.reset' => $accounts->reset($input),
                default => throw new AppError('Onbekende actie.', 404),
            };
        }
    } else {
        if ($scope === null) {
            throw new AppError('Log eerst in.', 401);
        }
        $result = $store->transaction($scope, static function (array &$state) use ($session, $action, $input, $accounts, $config): array {
            $actor = sessionActor($state, $session);
            if (!$actor || $actor['status'] !== 'active') {
                throw new AppError('Je account is niet actief.', 403);
            }
            if (str_starts_with($action, 'member.')) {
                return $accounts->manage($action, $state, $actor, $input);
            }
            if ($action === 'sharon.handoff') {
                return Sharon::dispatch($config->sharonUrl, $state, $actor, $input);
            }
            return Workflow::dispatch($action, $state, $actor, $input) ?? throw new AppError('Onbekende actie.', 404);
        });
        $deliver = $scope;
    }
    session_write_close();
    flushMail($store, $config, $deliver);
    reply(['ok' => true] + $result);
} catch (AppError $error) {
    reply(['error' => $error->getMessage()], $error->getCode());
} catch (Throwable $error) {
    error_log('Event Line API: ' . get_class($error) . ' in ' . basename($error->getFile()) . ':' . $error->getLine());
    reply(['error' => 'De bewerking kon niet worden verwerkt. Probeer het opnieuw.'], 500);
}
