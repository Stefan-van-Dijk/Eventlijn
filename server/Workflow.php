<?php
declare(strict_types=1);

final class Workflow
{
    private const CLOSE_RESULTS = [
        'Doel behaald',
        'Vervolgactie nodig',
        'Doel niet behaald',
        'Mondeling afgehandeld',
        'Administratief afgesloten',
    ];

    private const REVISABLE = ['rejected_manager', 'rejected_organization', 'revoked'];
    private const ATTENDING = ['approved', 'closed'];

    public static function dispatch(string $action, array &$state, array $actor, array $input): ?array
    {
        self::requireActive($actor);
        return match ($action) {
            'event.create' => self::createEvent($state, $actor, $input),
            'request.create' => self::createRequest($state, $actor, $input),
            'request.decide', 'request.evaluate', 'request.close', 'spot.propose', 'spot.publish', 'spot.withdraw'
                => self::updateRequest($state, $actor, $input, $action),
            default => null,
        };
    }

    public static function view(array $state, array $actor): array
    {
        self::requireActive($actor);
        $represented = [];
        $spotlights = [];
        $requests = [];
        foreach ($state['requests'] as $request) {
            if (in_array($request['status'], self::ATTENDING, true)) {
                $represented[$request['eventId']] = true;
            }
            if (($request['spot']['status'] ?? null) === 'published') {
                $spotlights[] = [
                    'name' => $request['name'],
                    'eventId' => $request['eventId'],
                    'text' => $request['spot']['text'],
                ];
            }
            if (self::canSee($request, $actor)) {
                $request['actions'] = self::actions($request, $actor);
                if (!self::isOwner($request, $actor) && $actor['role'] !== 'admin') {
                    unset($request['evaluation'], $request['closure'], $request['spot']);
                    foreach ($request['history'] as &$entry) {
                        if ($entry['type'] !== 'manager_decision') {
                            unset($entry['reason']);
                        }
                    }
                    unset($entry);
                }
                $requests[] = $request;
            }
        }
        $events = [];
        foreach ($state['events'] as $event) {
            $event['represented'] = $represented[$event['id']] ?? false;
            $events[] = $event;
        }
        usort($events, static fn(array $left, array $right): int => $left['start'] <=> $right['start']);
        return ['events' => $events, 'requests' => array_reverse($requests), 'spotlights' => $spotlights];
    }

    private static function createEvent(array &$state, array $actor, array $input): array
    {
        $title = self::text($input, 'title', 'Voer de naam van het evenement in.', 160);
        $start = self::date($input, 'start');
        $end = self::date($input, 'end');
        if ($end < $start) {
            throw new AppError('De einddatum moet op of na de startdatum liggen.', 422);
        }
        $kind = self::choice($input['kind'] ?? 'external', ['external', 'internal']);
        $registration = self::choice($input['registration'] ?? 'approval', ['approval', 'free']);
        if ($registration === 'free' && ($kind !== 'internal' || $actor['role'] !== 'admin')) {
            throw new AppError('Organisatiebeheer kan vrije inschrijving instellen voor interne evenementen.', 403);
        }

        $normalized = self::normalizeTitle($title);
        if (preg_match('/[\p{L}\p{N}]/u', $normalized) !== 1) {
            throw new AppError('Gebruik een letter of cijfer in de naam van het evenement.', 422);
        }
        foreach ($state['events'] as $existing) {
            $sameName = preg_match('/\A'.preg_quote($normalized, '/').'\z/iu', self::normalizeTitle($existing['title']));
            if ($sameName === 1 && $existing['start'] <= $end && $existing['end'] >= $start) {
                throw new AppError('Dit evenement staat al op de tijdlijn in de gekozen periode.', 409);
            }
        }

        $id = EventStore::id();
        $state['events'][$id] = [
            'id' => $id,
            'title' => $title,
            'start' => $start,
            'end' => $end,
            'location' => self::text($input, 'location', 'De locatie is te lang.', 180, false),
            'topic' => self::text($input, 'topic', 'Het onderwerp is te lang.', 120, false),
            'kind' => $kind,
            'registration' => $registration,
        ];
        return ['id' => $id];
    }

    private static function createRequest(array &$state, array $actor, array $input): array
    {
        $eventId = self::entityId($input['eventId'] ?? null);
        $event = $state['events'][$eventId] ?? null;
        if (!$event) {
            throw new AppError('Het evenement is niet gevonden.', 404);
        }
        $day = self::date($input, 'day');
        if ($day < $event['start'] || $day > $event['end'] || $day < self::today()) {
            throw new AppError('Kies een bezoekdag binnen het evenement die nog niet is verstreken.', 422);
        }
        foreach ($state['requests'] as $request) {
            if ($request['eventId'] === $eventId && $request['day'] === $day && self::isOwner($request, $actor)) {
                throw new AppError('Voor deze bezoekdag bestaat al een aanvraag.', 409);
            }
        }
        $needsApproval = $event['registration'] === 'approval';
        $manager = $needsApproval ? ($actor['manager'] ?? null) : null;
        if ($needsApproval && !self::canReview($state['members'][$manager ?? ''] ?? [], $actor['id'])) {
            throw new AppError('Laat organisatiebeheer eerst een actieve leidinggevende toewijzen.', 409);
        }

        $id = EventStore::id();
        $request = [
            'id' => $id,
            'eventId' => $eventId,
            'day' => $day,
            'userId' => $actor['id'],
            'name' => $actor['name'],
            'manager' => $manager,
            'goal' => self::text($input, 'goal', 'Vul in wat je wilt ontdekken (maximaal 2000 tekens).', 2000, $needsApproval),
            'benefit' => self::text($input, 'benefit', 'Vul in wat het je organisatie kan brengen (maximaal 2000 tekens).', 2000, $needsApproval),
            'status' => $needsApproval ? 'manager_pending' : 'approved',
            'history' => [],
        ];
        self::record($request, $actor['id'], 'created', $needsApproval ? 'Aanvraag ingediend' : 'Vrije inschrijving bevestigd');
        $state['requests'][$id] = $request;
        self::notify($state, $needsApproval ? $manager : $actor['id'],
            $needsApproval ? 'Nieuwe deelnameaanvraag' : 'Je inschrijving is bevestigd');
        return ['id' => $id, 'status' => $request['status']];
    }

    private static function updateRequest(array &$state, array $actor, array $input, string $action): array
    {
        $id = self::entityId($input['requestId'] ?? null);
        if (!isset($state['requests'][$id]) || !self::canSee($state['requests'][$id], $actor)) {
            throw new AppError('De aanvraag is niet gevonden.', 404);
        }
        $request =& $state['requests'][$id];
        $decision = $action === 'request.decide'
            ? self::choice($input['decision'] ?? null, ['approve', 'reject', 'revoke'])
            : null;
        $requiredAction = match (true) {
            $decision === 'revoke' => 'request.revoke',
            $decision !== null && in_array($request['status'], self::REVISABLE, true) => 'request.revise',
            default => $action,
        };
        if (!in_array($requiredAction, self::actions($request, $actor), true)) {
            throw new AppError('Deze actie is niet beschikbaar voor jouw rol of de huidige aanvraag.', 403);
        }

        match ($action) {
            'request.decide' => self::decide($state, $request, $actor['id'], $input, $decision),
            'request.evaluate' => self::evaluate($state, $request, $actor['id'], $input),
            'request.close' => self::close($state, $request, $actor['id'], $input),
            'spot.propose' => self::propose($state, $request, $actor['id'], $input),
            'spot.publish' => self::publish($state, $request, $actor['id'], $input),
            'spot.withdraw' => self::withdraw($state, $request, $actor['id']),
        };
        return ['id' => $id, 'status' => $request['status']];
    }

    private static function decide(array &$state, array &$request, string $actorId, array $input, string $decision): void
    {
        $reason = self::text($input, 'reason', 'Vul een toelichting in (maximaal 2000 tekens).', 2000, $decision !== 'approve');
        $managerStep = $request['status'] === 'manager_pending';
        $revision = in_array($request['status'], self::REVISABLE, true);
        [$status, $note] = match (true) {
            $decision === 'revoke' => ['revoked', 'Organisatiebeheer heeft deelname ingetrokken'],
            $managerStep && $decision === 'approve' => ['organization_pending', 'Leidinggevende heeft akkoord gegeven'],
            $managerStep => ['rejected_manager', 'Leidinggevende heeft de aanvraag afgewezen'],
            $revision && $decision === 'approve' => ['approved', 'Organisatiebeheer heeft het besluit herzien en deelname goedgekeurd'],
            $revision => ['rejected_organization', 'Organisatiebeheer heeft het besluit herzien en deelname afgewezen'],
            $decision === 'approve' => ['approved', 'Organisatiebeheer heeft deelname goedgekeurd'],
            default => ['rejected_organization', 'Organisatiebeheer heeft deelname afgewezen'],
        };
        $request['status'] = $status;
        self::record($request, $actorId, $managerStep ? 'manager_decision' : 'organization_decision', $note, $reason);
        self::notify($state, $request['userId'], 'Er is een besluit over je deelnameaanvraag');
        if ($status === 'organization_pending') {
            self::notifyAdmins($state, 'Een aanvraag wacht op een besluit van organisatiebeheer');
        }
    }

    private static function evaluate(array &$state, array &$request, string $actorId, array $input): void
    {
        $evaluation = [
            'outcome' => self::text($input, 'outcome', 'Vul in wat het bezoek heeft opgeleverd (maximaal 4000 tekens).', 4000),
            'reference' => self::text($input, 'reference', 'De kennisverwijzing is te lang.', 500, false),
        ];
        if (($request['evaluation'] ?? null) === $evaluation) {
            return;
        }
        $request['evaluation'] = $evaluation;
        self::record($request, $actorId, 'evaluation', 'Terugkoppeling opgeslagen');
        self::notifyAdmins($state, 'Er is een terugkoppeling ingevuld');
    }

    private static function close(array &$state, array &$request, string $actorId, array $input): void
    {
        $result = self::choice($input['result'] ?? null, self::CLOSE_RESULTS);
        $request['closure'] = [
            'result' => $result,
            'note' => self::text($input, 'note', 'De toelichting is te lang.', 2000, false),
            'reference' => self::text($input, 'reference', 'De kennisverwijzing is te lang.', 500, false),
        ];
        $request['status'] = 'closed';
        self::record($request, $actorId, 'closed', 'Deelname afgesloten');
        self::notify($state, $request['userId'], 'Je deelname is afgesloten');
    }

    private static function propose(array &$state, array &$request, string $actorId, array $input): void
    {
        $request['spot'] = [
            'text' => self::text($input, 'text', 'Vul je bijdrage in (maximaal 3000 tekens).', 3000),
            'status' => 'proposed',
        ];
        self::record($request, $actorId, 'spotlight', 'Bijdrage ter afstemming voorgesteld');
        self::notifyAdmins($state, 'Iemand stelt een spotlightbijdrage voor');
    }

    private static function publish(array &$state, array &$request, string $actorId, array $input): void
    {
        if (($input['confirmed'] ?? false) !== true) {
            throw new AppError('Bevestig eerst de afstemming met de medewerker.', 422);
        }
        $request['spot']['status'] = 'published';
        self::record($request, $actorId, 'spotlight', 'Bijdrage binnen de organisatie gepubliceerd');
        self::notify($state, $request['userId'], 'Je spotlightbijdrage is gepubliceerd');
    }

    private static function withdraw(array &$state, array &$request, string $actorId): void
    {
        unset($request['spot']);
        self::record($request, $actorId, 'spotlight', 'Spotlightbijdrage ingetrokken');
        if ($actorId === $request['userId']) {
            self::notifyAdmins($state, 'Een spotlightbijdrage is ingetrokken');
        } else {
            self::notify($state, $request['userId'], 'Je spotlightbijdrage is ingetrokken');
        }
    }

    private static function actions(array $request, array $actor): array
    {
        $actions = [];
        $status = $request['status'];
        $isAdmin = $actor['role'] === 'admin';
        $isOwner = self::isOwner($request, $actor);
        $canReview = $request['manager'] === $actor['id'] && self::canReview($actor, $request['userId']);
        $spot = $request['spot']['status'] ?? null;
        $visitStarted = $request['day'] <= self::today();

        if (($canReview && $status === 'manager_pending') || ($isAdmin && $status === 'organization_pending')) {
            $actions[] = 'request.decide';
        }
        if ($isAdmin && in_array($status, self::REVISABLE, true)) {
            $actions[] = 'request.revise';
        }
        if ($isAdmin && $status === 'approved') {
            $actions[] = 'request.revoke';
            if ($visitStarted) {
                $actions[] = 'request.close';
            }
        }
        if ($isOwner && $status === 'approved' && $visitStarted) {
            $actions[] = 'request.evaluate';
        }
        if ($isOwner && $status === 'closed' && $spot === null) {
            $actions[] = 'spot.propose';
        }
        if ($isAdmin && $status === 'closed' && $spot === 'proposed') {
            $actions[] = 'spot.publish';
        }
        if (($isOwner || $isAdmin) && $spot !== null) {
            $actions[] = 'spot.withdraw';
        }
        return $actions;
    }

    private static function requireActive(array $actor): void
    {
        if (($actor['status'] ?? null) !== 'active') {
            throw new AppError('Je account wacht op goedkeuring.', 403);
        }
    }

    private static function canSee(array $request, array $actor): bool
    {
        return $actor['role'] === 'admin'
            || self::isOwner($request, $actor)
            || ($request['manager'] === $actor['id'] && self::canReview($actor, $request['userId']));
    }

    private static function canReview(array $member, string $owner): bool
    {
        return isset($member['id']) && $member['id'] !== $owner
            && ($member['status'] ?? null) === 'active' && in_array($member['role'] ?? null, ['manager', 'admin'], true);
    }

    private static function isOwner(array $request, array $actor): bool
    {
        return $request['userId'] === $actor['id'];
    }

    private static function record(array &$request, string $actorId, string $type, string $note, string $reason = ''): void
    {
        $entry = ['type' => $type, 'actorId' => $actorId, 'at' => gmdate(DATE_ATOM), 'note' => $note];
        if ($reason !== '') {
            $entry['reason'] = $reason;
        }
        $request['history'][] = $entry;
    }

    private static function notify(array &$state, string $userId, string $subject): void
    {
        $member = $state['members'][$userId] ?? [];
        if (($member['status'] ?? null) === 'active') {
            MailQueue::add($state, $member['email'], $subject, 'Open Event Line om de bijgewerkte aanvraag te bekijken.');
        }
    }

    private static function notifyAdmins(array &$state, string $subject): void
    {
        foreach ($state['members'] as $id => $member) {
            if ($member['role'] === 'admin') {
                self::notify($state, $id, $subject);
            }
        }
    }

    private static function text(array $input, string $field, string $message, int $limit, bool $required = true): string
    {
        $value = $input[$field] ?? '';
        if (!is_string($value) || strlen($value) > $limit * 4 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', $value) === 1) {
            throw new AppError($message, 422);
        }
        $value = trim($value);
        if (($required && $value === '') || preg_match('/\A.{0,'.$limit.'}\z/us', $value) !== 1) {
            throw new AppError($message, 422);
        }
        return $value;
    }

    private static function entityId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/', $value) !== 1) {
            throw new AppError('De gevraagde verwijzing is niet gevonden.', 404);
        }
        return $value;
    }

    private static function date(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || strlen($value) !== 10 || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1
            || !checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4))) {
            throw new AppError('Vul een geldige datum in.', 422);
        }
        return $value;
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
    }

    private static function normalizeTitle(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '', $value) ?? '';
    }

    private static function choice(mixed $value, array $options): string
    {
        if (!in_array($value, $options, true)) {
            throw new AppError('Kies een geldige optie.', 422);
        }
        return $value;
    }
}
