<?php
declare(strict_types=1);

final class Sharon
{
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
