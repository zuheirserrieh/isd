<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$city = normalizeCityName($_GET['city'] ?? '');
if ($city === '') {
    jsonResponse([
        'error' => 'City name is required',
        'history' => 'No history available',
        'img1' => 'https://via.placeholder.com/900x600?text=No+Image',
        'img2' => 'https://via.placeholder.com/900x600?text=No+Image',
    ], 400);
}

$profile = fetchWikipediaCityProfile($city);

jsonResponse([
    'history' => $profile['summary'],
    'img1' => $profile['images'][0],
    'img2' => $profile['images'][1],
]);
