<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$city = normalizeCityName($_GET['city'] ?? '');
if ($city === '') {
    jsonResponse(['error' => 'City parameter is required'], 400);
}

$apiKey = envValue('TICKETMASTER_API_KEY');
if ($apiKey === null) {
    jsonResponse([
        'events' => [],
        'error' => 'Events are unavailable until TICKETMASTER_API_KEY is set in .env.',
    ]);
}

$url = 'https://app.ticketmaster.com/discovery/v2/events.json?' . http_build_query([
    'apikey' => $apiKey,
    'city' => $city,
    'sort' => 'date,asc',
    'size' => 5,
]);

$data = httpGetJson($url);
if ($data === null) {
    jsonResponse([
        'events' => [],
        'error' => 'Failed to fetch events from Ticketmaster.',
    ], 502);
}

$rawEvents = $data['_embedded']['events'] ?? [];
if (!is_array($rawEvents) || $rawEvents === []) {
    jsonResponse(['events' => []]);
}

$events = array_map(static function (array $event): array {
    $localDate = $event['dates']['start']['localDate'] ?? null;
    $localTime = $event['dates']['start']['localTime'] ?? null;

    $formattedDate = 'Date to be announced';
    if (is_string($localDate) && $localDate !== '') {
        $date = new DateTime($localDate);
        $formattedDate = $date->format('F j, Y');
        if (is_string($localTime) && $localTime !== '') {
            $formattedDate .= ' at ' . date('g:i A', strtotime($localTime));
        }
    }

    $venue = $event['_embedded']['venues'][0]['name'] ?? 'Venue TBA';

    $description = trim((string) ($event['info'] ?? $event['pleaseNote'] ?? 'No description available'));
    if ($description === '') {
        $description = 'No description available';
    }

    return [
        'title' => (string) ($event['name'] ?? 'Untitled event'),
        'date' => $formattedDate,
        'venue' => (string) $venue,
        'description' => $description,
        'url' => (string) ($event['url'] ?? '#'),
        'image' => (string) ($event['images'][0]['url'] ?? ''),
    ];
}, $rawEvents);

jsonResponse(['events' => $events]);
