<?php

declare(strict_types=1);

use LIVCK\Cloud\Tests\Contract\Support\InScope;
use LIVCK\Cloud\Tests\Contract\Support\OpenApiSpec;
use LIVCK\Cloud\Tests\Contract\Support\Operation;
use LIVCK\Cloud\Tests\Contract\Support\OutOfScope;
use LIVCK\Cloud\Tests\Contract\Support\RequestContract;
use LIVCK\Cloud\Tests\Contract\Support\SdkCalls;

/**
 * Every operation of the API document is either reachable through a public SDK method or
 * listed in OutOfScope with a reason. An operation the server adds fails here until one of
 * the two happens, so the SDK cannot fall behind the API without anyone noticing.
 */

/**
 * The operations the catalog of SDK calls reaches, keyed by `METHOD /path`.
 *
 * @return array<string, true>
 */
function exercisedOperations(): array
{
    $contract = new RequestContract(OpenApiSpec::load());
    $exercised = [];

    foreach (SdkCalls::all() as $call) {
        foreach ($call->record() as $request) {
            $operation = $contract->operationFor($request);

            if ($operation instanceof Operation) {
                $exercised[$operation->key()] = true;
            }
        }
    }

    return $exercised;
}

describe('operation coverage', function (): void {
    it('reaches every operation of the document that is not allowlisted', function (): void {
        $exercised = exercisedOperations();
        $allowlisted = OutOfScope::operations();
        $missing = [];

        foreach (OpenApiSpec::load()->operations() as $key => $operation) {
            if (! isset($exercised[$key]) && ! isset($allowlisted[$key])) {
                $missing[] = sprintf('%s (%s)', $key, $operation->id);
            }
        }

        expect($missing)->toBe([], sprintf(
            "These operations are in the API document, but no SDK method calls them. Implement them, or add them to OutOfScope::operations() with a reason:\n - %s",
            implode("\n - ", $missing),
        ));
    });

    it('implements every operation the SDK promises', function (): void {
        $exercised = exercisedOperations();
        $missing = array_values(array_filter(InScope::operations(), static fn(string $key): bool => ! isset($exercised[$key])));

        expect($missing)->toBe([], sprintf("In-scope operations without an SDK method:\n - %s", implode("\n - ", $missing)));
    });

    it('keeps the allowlist to operations that exist and are not implemented', function (): void {
        $operations = OpenApiSpec::load()->operations();
        $exercised = exercisedOperations();
        $stale = [];
        $contradicted = [];

        foreach (array_keys(OutOfScope::operations()) as $key) {
            if (! isset($operations[$key])) {
                $stale[] = $key;
            }

            if (isset($exercised[$key])) {
                $contradicted[] = $key;
            }
        }

        expect($stale)->toBe([], sprintf("Allowlisted operations the document no longer has:\n - %s", implode("\n - ", $stale)))
            ->and($contradicted)->toBe([], sprintf("Allowlisted operations the SDK calls anyway:\n - %s", implode("\n - ", $contradicted)))
            ->and(array_intersect(InScope::operations(), array_keys(OutOfScope::operations())))->toBe([]);
    });

    it('gives every allowlisted operation a reason', function (): void {
        foreach (OutOfScope::operations() as $key => $reason) {
            expect(trim($reason))->not->toBe('', sprintf('%s is allowlisted without a reason.', $key));
        }
    });

    it('knows every in-scope operation by the key the document uses', function (): void {
        $operations = OpenApiSpec::load()->operations();
        $unknown = array_values(array_filter(InScope::operations(), static fn(string $key): bool => ! isset($operations[$key])));

        expect($unknown)->toBe([], sprintf("In-scope operations the document does not declare:\n - %s", implode("\n - ", $unknown)));
    });
});
