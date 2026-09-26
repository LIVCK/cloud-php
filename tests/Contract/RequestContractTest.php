<?php

declare(strict_types=1);

use LIVCK\Cloud\Tests\Contract\Support\OpenApiSpec;
use LIVCK\Cloud\Tests\Contract\Support\RequestContract;
use LIVCK\Cloud\Tests\Contract\Support\SdkCalls;

/**
 * Every public SDK method is called against a fake client; each request it sends must
 * hit an operation of the API document, send only declared query parameters with valid
 * values, and carry a body the operation's request schema accepts.
 */
$names = SdkCalls::names();

it('sends what the API document declares', function (string $name): void {
    $contract = new RequestContract(OpenApiSpec::load());
    $requests = SdkCalls::get($name)->record();

    expect($requests)->not->toBeEmpty(sprintf('SDK call "%s" sent no request.', $name));

    $problems = [];

    foreach ($requests as $request) {
        $problems = [...$problems, ...$contract->problems($request)];
    }

    expect($problems)->toBe([], sprintf(
        "The request of \"%s\" drifts from the API document:\n - %s",
        $name,
        implode("\n - ", $problems),
    ));
})->with(array_combine($names, $names));
