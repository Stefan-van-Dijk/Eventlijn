<?php
declare(strict_types=1);

final class Accounts
{
    public const REGISTRY = '00000000000000000000000000000000';

    public function __construct(private EventStore $store, private Config $config) {}

    public static function text(array $input, string $key, int $length, bool $required = true): string
    {
        $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        if (($required && $value === '') || strlen($value) > $length || str_contains($value, "\0")) {
            throw new AppError('Controleer het veld ' . $key . '.', 422);
        }
        return $value;
    }

    private static function email(array $input, string $key = 'email'): string
    {
        $email = strtolower(self::text($input, $key, 254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppError('Vul een geldig e-mailadres in.', 422);
        }
        return $email;
    }

    private static function password(array $input): string
    {
        $password = $input['password'] ?? null;
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new AppError('Gebruik een wachtwoord van minimaal 12 tekens en maximaal 72 bytes.', 422);
        }
        return $password;
    }

    private static function splitToken(string $token): array
    {
        if (!preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/', $token, $parts) || $parts[1] === self::REGISTRY) {
            throw new AppError('Deze link is ongeldig of verlopen.', 400);
        }
        return [$parts[1], hash('sha256', $parts[2])];
    }

    private function token(array &$state, string $kind, array $target, int $ttl): string
    {
        foreach ($state['tokens'] ?? [] as $key => $record) {
            if ($record['expires'] < time() || ($record['kind'] === $kind &&
                    ($record['id'] ?? $record['email']) === ($target['id'] ?? $target['email']))) {
                unset($state['tokens'][$key]);
            }
        }
        $raw = bin2hex(random_bytes(32));
        $state['tokens'][hash('sha256', $raw)] = ['kind' => $kind, 'expires' => time() + $ttl] + $target;
        return $this->config->baseUrl . '#' . $kind . '=' . $state['organization'] . '.' . $raw;
    }

    public static function publicMember(array $member): array
    {
        return array_intersect_key($member, array_flip(['id', 'name', 'email', 'role', 'status', 'manager', 'managerEmail']));
    }

    public function rateLimit(string $group, int $limit, int $seconds): void
    {
        $key = hash('sha256', $group . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $allowed = $this->store->transaction(self::REGISTRY, static function (array &$state) use ($key, $limit, $seconds): bool {
            $now = time();
            foreach ($state['rates'] ?? [] as $hash => $rate) {
                if ($rate['until'] <= $now) {
                    unset($state['rates'][$hash]);
                }
            }
            $rate = $state['rates'][$key] ?? ['until' => $now + $seconds, 'hits' => 0];
            if ($rate['hits'] >= $limit) {
                return false;
            }
            $rate['hits']++;
            $state['rates'][$key] = $rate;
            return true;
        });
        if (!$allowed) {
            throw new AppError('Te veel pogingen. Probeer het later opnieuw.', 429);
        }
    }

    private function requireOrganization(string $id): void
    {
        $known = $this->store->transaction(self::REGISTRY, static fn(array $state): bool => isset($state['organizations'][$id]));
        if (!$known) {
            throw new AppError('Deze link is ongeldig of verlopen.', 400);
        }
    }

    private function account(string $email): ?array
    {
        return $this->store->transaction(self::REGISTRY, static fn(array $state) => $state['accounts'][hash('sha256', $email)] ?? null);
    }

    public function register(array $input): string
    {
        $email = self::email($input);
        $name = self::text($input, 'name', 140);
        $password = password_hash(self::password($input), PASSWORD_DEFAULT);
        $invite = self::text($input, 'invite', 100, false);
        $managerEmail = self::text($input, 'managerEmail', 254, false) !== '' ? self::email($input, 'managerEmail') : null;
        [$org, $inviteHash] = $invite !== '' ? self::splitToken($invite) : [EventStore::id(), null];
        if ($inviteHash !== null) {
            $this->requireOrganization($org);
        }
        $organization = $inviteHash === null ? self::text($input, 'organization', 180) : null;
        $id = EventStore::id();
        $this->store->transaction(self::REGISTRY, function (array &$registry) use ($id, $org, $email, $name, $password, $managerEmail, $inviteHash, $organization): void {
            $key = hash('sha256', $email);
            if (isset($registry['accounts'][$key])) {
                throw new AppError('Registratie is niet mogelijk. Probeer in te loggen of vraag een nieuw wachtwoord aan.', 409);
            }
            $this->store->transaction($org, function (array &$state) use ($id, $email, $name, $password, $managerEmail, $inviteHash, $organization): void {
                $role = 'admin';
                if ($inviteHash !== null) {
                    $invitation = $state['tokens'][$inviteHash] ?? null;
                    if (!$invitation || $invitation['kind'] !== 'invite' || $invitation['expires'] < time() || $invitation['email'] !== $email) {
                        throw new AppError('Deze uitnodiging is ongeldig, verlopen of hoort bij een ander adres.', 400);
                    }
                    $role = $invitation['role'];
                    unset($state['tokens'][$inviteHash]);
                } else {
                    $state['name'] = $organization;
                }
                $state['members'][$id] = [
                    'id' => $id, 'name' => $name, 'email' => $email, 'password' => $password,
                    'role' => $role, 'manager' => null, 'managerEmail' => $managerEmail, 'status' => 'unverified',
                    'sessionVersion' => 1,
                ];
                $url = $this->token($state, 'verify', ['id' => $id], 86400);
                MailQueue::add($state, $email, 'Bevestig je Event Line-account', "Bevestig je e-mailadres via deze link (24 uur geldig):\n\n" . $url);
            });
            $registry['accounts'][$key] = ['org' => $org, 'id' => $id];
            $registry['organizations'][$org] = true;
        });
        return $org;
    }

    public function verify(string $token): string
    {
        [$org, $hash] = self::splitToken($token);
        $this->requireOrganization($org);
        $this->store->transaction($org, static function (array &$state) use ($hash): void {
            $entry = self::validToken($state, $hash, 'verify');
            $member = &$state['members'][$entry['id']];
            $member['status'] = $member['role'] === 'admin' ? 'active' : 'pending';
            unset($state['tokens'][$hash]);
            if ($member['status'] === 'pending') {
                foreach ($state['members'] as $admin) {
                    if ($admin['role'] === 'admin' && $admin['status'] === 'active') {
                        MailQueue::add($state, $admin['email'], 'Nieuw Event Line-account ter beoordeling', 'Log in bij Event Line om het account te beoordelen.');
                    }
                }
            }
        });
        return $org;
    }

    private static function validToken(array $state, string $hash, string $kind): array
    {
        $entry = $state['tokens'][$hash] ?? null;
        if (!$entry || $entry['kind'] !== $kind || $entry['expires'] < time() || !isset($state['members'][$entry['id']])) {
            throw new AppError('Deze link is ongeldig of verlopen.', 400);
        }
        return $entry;
    }

    public function login(array $input): array
    {
        $account = $this->account(self::email($input));
        $password = self::password($input);
        $member = $account ? $this->store->transaction($account['org'], static fn(array $state) => $state['members'][$account['id']] ?? null) : null;
        $dummy = '$2y$10$w/2f1WSqy3D8lR2WRjEth.Nhphm91cM3FrEzg1YKItZq/ekKOQZfK';
        if (!password_verify($password, $member['password'] ?? $dummy) || !$member || $member['status'] === 'rejected') {
            throw new AppError('E-mailadres of wachtwoord is onjuist.', 401);
        }
        if ($member['status'] === 'unverified') {
            throw new AppError('Bevestig eerst je e-mailadres. Via wachtwoord vergeten kun je een nieuwe link aanvragen.', 403);
        }
        return $account + ['version' => $member['sessionVersion']];
    }

    public function loginSharon(array $claims): array
    {
        $sharonId = self::text($claims, 'id', 12);
        $personId = self::text($claims, 'personId', 12);
        if (!preg_match('/\A[A-Za-z0-9_-]{12}\z/', $sharonId)
            || !preg_match('/\A[A-Za-z0-9_-]{12}\z/', $personId)) {
            throw new AppError('Ongeldige Sharon-identiteit.', 403);
        }
        $email = self::email($claims);
        $name = self::text($claims, 'name', 240);
        return $this->store->transaction(self::REGISTRY, function (array &$registry) use ($sharonId, $personId, $email, $name): array {
            $linked = $registry['sharonAccounts'][$sharonId] ?? null;
            $existing = $registry['accounts'][hash('sha256', $email)] ?? null;
            if ($linked && ($linked['personId'] !== $personId || $linked['email'] !== $email
                    || $existing === null || $linked['org'] !== $existing['org'] || $linked['id'] !== $existing['id'])) {
                throw new AppError('De Sharon-identiteit hoort bij een ander Event Line-account.', 409);
            }
            if ($existing !== null) {
                foreach ($registry['sharonAccounts'] ?? [] as $id => $binding) {
                    if ($id !== $sharonId && $binding['org'] === $existing['org'] && $binding['id'] === $existing['id']) {
                        throw new AppError('Dit Event Line-account is al met een ander Sharon-account verbonden.', 409);
                    }
                }
                $version = $this->store->transaction($existing['org'], static function (array $state) use ($existing, $email): int {
                    $member = $state['members'][$existing['id']] ?? null;
                    if (!$member || $member['email'] !== $email || !in_array($member['status'], ['active', 'pending'], true)) {
                        throw new AppError('Dit Event Line-account heeft nog geen toegang. Controleer de e-mailbevestiging of neem contact op met de beheerder.', 403);
                    }
                    return $member['sessionVersion'];
                });
                $account = $existing;
            } else {
                $account = ['org' => EventStore::id(), 'id' => EventStore::id()];
                $version = 1;
                $this->store->transaction($account['org'], static function (array &$state) use ($account, $name, $email): void {
                    $state['name'] = 'Persoonlijk';
                    $state['members'][$account['id']] = [
                        'id' => $account['id'], 'name' => $name, 'email' => $email,
                        'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                        'role' => 'admin', 'manager' => null, 'managerEmail' => null,
                        'status' => 'active', 'sessionVersion' => 1,
                    ];
                });
                $registry['accounts'][hash('sha256', $email)] = $account;
                $registry['organizations'][$account['org']] = true;
            }
            $registry['sharonAccounts'][$sharonId] = $account + ['personId' => $personId, 'email' => $email];
            return $account + ['version' => $version];
        });
    }

    public function createAppSession(array $account): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->store->transaction(self::REGISTRY, static function (array &$state) use ($token, $account): void {
            foreach ($state['appSessions'] ?? [] as $hash => $entry) {
                if ($entry['until'] <= time()) {
                    unset($state['appSessions'][$hash]);
                }
            }
            $owned = array_keys(array_filter($state['appSessions'] ?? [], static fn(array $entry): bool =>
                $entry['account']['org'] === $account['org'] && $entry['account']['id'] === $account['id']));
            foreach (array_slice($owned, 0, max(0, count($owned) - 19)) as $hash) {
                unset($state['appSessions'][$hash]);
            }
            $state['appSessions'][hash('sha256', $token)] = ['account' => $account, 'until' => time() + 7200];
        });
        return $token;
    }

    public function readAppSession(string $token): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
            throw new AppError('De app-aanmelding is verlopen.', 401);
        }
        return $this->store->transaction(self::REGISTRY, function (array &$state) use ($token): array {
            $hash = hash('sha256', $token);
            $entry = $state['appSessions'][$hash] ?? null;
            if (!$entry || $entry['until'] <= time()) {
                throw new AppError('De app-aanmelding is verlopen.', 401);
            }
            $account = $entry['account'];
            $valid = $this->store->transaction($account['org'], static function (array $organization) use ($account): bool {
                $member = $organization['members'][$account['id']] ?? null;
                return $member && $member['sessionVersion'] === $account['version']
                    && in_array($member['status'], ['active', 'pending'], true);
            });
            if (!$valid) {
                throw new AppError('De app-aanmelding is verlopen.', 401);
            }
            return $account;
        });
    }

    public function revokeAppSession(string $token): void
    {
        $this->store->transaction(self::REGISTRY, static function (array &$state) use ($token): void {
            unset($state['appSessions'][hash('sha256', $token)]);
        });
    }

    public function forgot(array $input): ?string
    {
        $email = self::email($input);
        $account = $this->account($email);
        if (!$account) {
            return null;
        }
        $this->store->transaction($account['org'], function (array &$state) use ($account, $email): void {
            $member = $state['members'][$account['id']] ?? null;
            if (!$member || $member['status'] === 'rejected') {
                return;
            }
            $kind = $member['status'] === 'unverified' ? 'verify' : 'reset';
            $url = $this->token($state, $kind, ['id' => $account['id']], 3600);
            MailQueue::add($state, $email, 'Toegang tot Event Line', "Gebruik deze link binnen één uur:\n\n" . $url);
        });
        return $account['org'];
    }

    public function reset(array $input): string
    {
        [$org, $hash] = self::splitToken(self::text($input, 'token', 100));
        $this->requireOrganization($org);
        $password = password_hash(self::password($input), PASSWORD_DEFAULT);
        $this->store->transaction($org, static function (array &$state) use ($hash, $password): void {
            $entry = self::validToken($state, $hash, 'reset');
            $member = &$state['members'][$entry['id']];
            $member['password'] = $password;
            $member['sessionVersion']++;
            unset($state['tokens'][$hash]);
        });
        return $org;
    }

    public function manage(string $action, array &$state, array $actor, array $input): array
    {
        if ($actor['role'] !== 'admin') {
            throw new AppError('Je hebt hiervoor geen bevoegdheid.', 403);
        }
        if ($action === 'member.invite') {
            $email = self::email($input);
            $role = self::text($input, 'role', 20);
            if (!in_array($role, ['manager', 'employee'], true)) {
                throw new AppError('Kies medewerker of leidinggevende.', 422);
            }
            foreach ($state['members'] as $member) {
                if ($member['email'] === $email) {
                    throw new AppError('Dit adres is al geregistreerd binnen de organisatie.', 409);
                }
            }
            $url = $this->token($state, 'invite', ['email' => $email, 'role' => $role], 604800);
            MailQueue::add($state, $email, 'Uitnodiging voor Event Line', "Je bent uitgenodigd voor " . $state['name'] . ". Registreer je binnen zeven dagen via:\n\n" . $url);
            return [];
        }
        $id = self::text($input, 'id', 32);
        $member = $state['members'][$id] ?? null;
        if (!$member || $member['role'] === 'admin') {
            throw new AppError('Dit account kan hier niet worden gewijzigd.', 404);
        }
        if ($action === 'member.reject' && $member['status'] === 'pending') {
            $state['members'][$id]['status'] = 'rejected';
            $state['members'][$id]['sessionVersion']++;
            MailQueue::add($state, $member['email'], 'Besluit over je Event Line-account', 'Je registratie is afgewezen. Neem voor vragen contact op met je organisatiebeheerder.');
            return [];
        }
        if (($action === 'member.approve' && $member['status'] !== 'pending')
            || ($action === 'member.manager' && $member['status'] !== 'active')
            || !in_array($action, ['member.approve', 'member.manager'], true)) {
            throw new AppError('Deze wijziging is niet beschikbaar voor dit account.', 409);
        }
        $managerId = self::text($input, 'manager', 32, false);
        $manager = $state['members'][$managerId] ?? null;
        if ($managerId !== '' || $member['role'] === 'employee') {
            if (!$manager || $managerId === $id || $manager['status'] !== 'active'
                || !in_array($manager['role'], ['manager', 'admin'], true)) {
                throw new AppError('Kies een actieve leidinggevende.', 422);
            }
        }
        $state['members'][$id]['manager'] = $managerId ?: null;
        if ($action === 'member.approve') {
            $state['members'][$id]['status'] = 'active';
            MailQueue::add($state, $member['email'], 'Je Event Line-account is actief', 'Je kunt nu inloggen bij Event Line.');
        }
        return [];
    }
}
