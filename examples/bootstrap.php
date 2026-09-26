<?php

declare(strict_types=1);

/*
 * Shared by the example scripts: the autoloader and a client configured from the
 * environment. Not an example itself.
 *
 *   LIVCK_CLOUD_TOKEN     an API token of your organization (lvk_...), required
 *   LIVCK_CLOUD_BASE_URI  another API base than https://api.livck.cloud/v1, optional
 */

use LIVCK\Cloud\ClientOptions;
use LIVCK\Cloud\CloudClient;

// From a clone of this repository, or from vendor/livck/cloud-php inside your project.
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;

        break;
    }
}

function client(string $example): CloudClient
{
    $token = getenv('LIVCK_CLOUD_TOKEN');
    $baseUri = getenv('LIVCK_CLOUD_BASE_URI');

    if ($token === false || trim($token) === '') {
        fail('Set LIVCK_CLOUD_TOKEN to an API token of your organization (lvk_...).');
    }

    return new CloudClient($token, new ClientOptions(
        baseUri: $baseUri === false || $baseUri === '' ? ClientOptions::DEFAULT_BASE_URI : $baseUri,
        userAgentSuffix: 'livck-examples/' . $example,
    ));
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);

    exit(1);
}
