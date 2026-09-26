<?php

declare(strict_types=1);

use LIVCK\Cloud\Tests\Contract\Support\OpenApiSpec;
use LIVCK\Cloud\Tests\Contract\Support\ResponseSamples;
use LIVCK\Cloud\Tests\Contract\Support\ResponseSpec;
use LIVCK\Cloud\Tests\Contract\Support\UndeclaredKeys;

/**
 * Every response body the unit tests feed the SDK must be one the API document allows for
 * its operation and status, down to the last key: a fixture that drifts would let the
 * DTOs pass on data the server never sends.
 */
$names = ResponseSamples::names();

it('feeds the SDK response bodies the API document describes', function (string $name): void {
    $spec = OpenApiSpec::load();
    $sample = ResponseSamples::get($name);
    $operation = $spec->operation($sample->operation);
    $response = $operation->response($sample->status);

    expect($response)->toBeInstanceOf(ResponseSpec::class, sprintf('%s declares no %d response.', $sample->operation, $sample->status));

    $pointer = $response instanceof ResponseSpec ? $response->jsonPointer() : null;

    expect($pointer)->toBeString(sprintf('%s declares no JSON body for its %d response.', $sample->operation, $sample->status));

    $data = OpenApiSpec::decode($sample->response->body);
    $problems = [
        ...$spec->validate($data, (string) $pointer),
        ...(new UndeclaredKeys($spec))->in($data, $spec->schemaAt((string) $pointer)),
    ];

    expect($problems)->toBe([], sprintf(
        "The fixture \"%s\" (%s, %d) drifts from the API document:\n - %s",
        $name,
        $sample->operation,
        $sample->status,
        implode("\n - ", $problems),
    ));
})->with(array_combine($names, $names));
