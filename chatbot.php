<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$data = json_decode(file_get_contents('php://input'), true);
$message = trim((string) ($data['message'] ?? ''));

if ($message === '') {
    jsonResponse(['reply' => 'No message received.'], 400);
}

$city = '';
if (preg_match('/City context:\s*(.+)$/mi', $message, $matches)) {
    $city = normalizeCityName($matches[1]);
    $message = trim((string) preg_replace('/\n*\s*City context:\s*.+$/mi', '', $message));
}

$question = strtolower($message);
if ($city === '' && preg_match('/\b(?:in|about|for)\s+([A-Za-z\s,\-]+)/i', $message, $matches)) {
    $city = normalizeCityName($matches[1]);
}

if ($city === '') {
    jsonResponse([
        'reply' => 'Ask about a specific city, for example: "What is Beirut famous for?"',
    ]);
}

$profile = fetchWikipediaCityProfile($city);
$summary = $profile['summary'];

$reply = "About {$city}: {$summary}";

if (str_contains($question, 'famous') || str_contains($question, 'known for')) {
    $reply = "{$city} is best known for this: {$summary}";
} elseif (str_contains($question, 'history') || str_contains($question, 'historical')) {
    $reply = "Here is a short history of {$city}: {$summary}";
} elseif (str_contains($question, 'where')) {
    $reply = "{$city} can be explored in the map section of the page. Summary: {$summary}";
} elseif (str_contains($question, 'event')) {
    $reply = "Check the events panel for live listings. For context, here is a short overview of {$city}: {$summary}";
} elseif (str_contains($question, 'tell me more') || str_contains($question, 'about')) {
    $reply = $summary;
}

jsonResponse(['reply' => $reply]);
