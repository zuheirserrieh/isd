<?php

declare(strict_types=1);

function loadEnvFile(string $path): void
{
    static $loaded = false;

    if ($loaded || !is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        $value = trim($value, "\"'");
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }

    $loaded = true;
}

function envValue(string $name, ?string $default = null): ?string
{
    loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');

    $value = $_ENV[$name] ?? getenv($name);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function httpGetJson(string $url): ?array
{
    $response = false;
    $status = 0;

    $restoreEnv = static function (array $values): void {
        foreach ($values as $name => $value) {
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    };

    $proxyVars = ['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'];
    $originalProxyValues = [];
    foreach ($proxyVars as $proxyVar) {
        $existing = getenv($proxyVar);
        $originalProxyValues[$proxyVar] = $existing === false ? null : $existing;
        putenv($proxyVar);
        unset($_ENV[$proxyVar]);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: CityExplorer/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400 || $error !== '') {
            $restoreEnv($originalProxyValues);
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'User-Agent: CityExplorer/1.0',
                ]),
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        if ($response === false || $status >= 400) {
            $restoreEnv($originalProxyValues);
            return null;
        }
    }

    $restoreEnv($originalProxyValues);

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function normalizeCityName(string $city): string
{
    return trim(preg_replace('/\s+/', ' ', $city) ?? '');
}

function fetchWikipediaCityProfile(string $city): array
{
    $city = normalizeCityName($city);
    if ($city === '') {
        return [
            'title' => '',
            'summary' => 'No city information available.',
            'images' => [
                'https://via.placeholder.com/900x600?text=No+Image',
                'https://via.placeholder.com/900x600?text=No+Image',
            ],
        ];
    }

    $searchUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query',
        'list' => 'search',
        'srsearch' => $city,
        'format' => 'json',
        'utf8' => 1,
        'origin' => '*',
    ]);

    $searchData = httpGetJson($searchUrl);
    $title = $searchData['query']['search'][0]['title'] ?? $city;

    $summaryUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($title);
    $summaryData = httpGetJson($summaryUrl);
    $summary = $summaryData['extract'] ?? 'No history available.';

    $images = [];
    foreach (['originalimage', 'thumbnail'] as $key) {
        $url = $summaryData[$key]['source'] ?? null;
        if (is_string($url) && $url !== '') {
            $images[] = $url;
        }
    }

    $mediaUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query',
        'prop' => 'images',
        'titles' => $title,
        'format' => 'json',
        'utf8' => 1,
        'origin' => '*',
    ]);

    $mediaData = httpGetJson($mediaUrl);
    foreach ($mediaData['query']['pages'] ?? [] as $page) {
        foreach ($page['images'] ?? [] as $image) {
            $imageTitle = $image['title'] ?? '';
            if (!preg_match('/\.(jpg|jpeg|png)$/i', $imageTitle)) {
                continue;
            }

            $imageInfoUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
                'action' => 'query',
                'titles' => $imageTitle,
                'prop' => 'imageinfo',
                'iiprop' => 'url',
                'format' => 'json',
                'utf8' => 1,
                'origin' => '*',
            ]);

            $imageInfoData = httpGetJson($imageInfoUrl);
            foreach ($imageInfoData['query']['pages'] ?? [] as $imagePage) {
                $url = $imagePage['imageinfo'][0]['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $images[] = $url;
                }
            }

            if (count(array_unique($images)) >= 2) {
                break 2;
            }
        }
    }

    $images = array_values(array_unique(array_filter($images)));
    while (count($images) < 2) {
        $images[] = 'https://via.placeholder.com/900x600?text=No+Image';
    }

    return [
        'title' => $title,
        'summary' => $summary,
        'images' => array_slice($images, 0, 2),
    ];
}
