<?php

declare(strict_types=1);

/**
 * Refreshes the vendored API document the contract tests run against.
 *
 *     composer spec:update                                                        # production
 *     LIVCK_OPENAPI_URL=http://localhost:8000/docs/api.json composer spec:update  # a local server
 *
 * The download is normalized (see SpecNormalizer) so that a refresh yields a readable diff.
 */

use LIVCK\Cloud\Tests\Contract\Support\SpecNormalizer;

require __DIR__ . '/../vendor/autoload.php';

const DEFAULT_URL = 'https://api.livck.cloud/docs/api.json';
const TARGET = __DIR__ . '/../tests/Fixtures/openapi/livck-cloud-v1.json';

$url = getenv('LIVCK_OPENAPI_URL');
$url = is_string($url) && $url !== '' ? $url : DEFAULT_URL;

$context = stream_context_create([
    'http' => ['timeout' => 30, 'header' => "Accept: application/json\r\n", 'ignore_errors' => true],
]);

$json = @file_get_contents($url, false, $context);
$statusLine = $http_response_header[0] ?? '';

if ($json === false || preg_match('/\s200\s/', $statusLine) !== 1) {
    fwrite(STDERR, sprintf("Could not download %s (%s).\n", $url, $statusLine === '' ? 'no response' : $statusLine));
    exit(1);
}

try {
    $normalized = SpecNormalizer::normalize($json);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("The response of %s is not an OpenAPI document: %s\n", $url, $e->getMessage()));
    exit(1);
}

$document = json_decode($normalized, true, 512, JSON_THROW_ON_ERROR);
$paths = is_array($document) && is_array($document['paths'] ?? null) ? $document['paths'] : [];
$operations = 0;

foreach ($paths as $item) {
    if (is_array($item)) {
        $operations += count(array_intersect_key($item, array_flip(['get', 'post', 'put', 'patch', 'delete', 'head', 'options'])));
    }
}

$components = is_array($document) && is_array($document['components'] ?? null) ? $document['components'] : [];
$schemas = is_array($components['schemas'] ?? null) ? count($components['schemas']) : 0;

if (file_put_contents(TARGET, $normalized) === false) {
    fwrite(STDERR, sprintf("Could not write %s.\n", TARGET));
    exit(1);
}

printf(
    "Wrote %s from %s: %d paths, %d operations, %d schemas.\n",
    (string) realpath(TARGET),
    $url,
    count($paths),
    $operations,
    $schemas,
);
