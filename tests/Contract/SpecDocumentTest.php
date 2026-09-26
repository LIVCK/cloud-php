<?php

declare(strict_types=1);

use LIVCK\Cloud\Tests\Contract\Support\OpenApiSpec;
use LIVCK\Cloud\Tests\Contract\Support\SpecNormalizer;

describe('the vendored API document', function (): void {
    it('is stored in its normalized form (refresh it with composer spec:update)', function (): void {
        $file = file_get_contents(OpenApiSpec::PATH);

        expect($file)->toBeString();
        expect(SpecNormalizer::normalize((string) $file))->toBe($file);
    });

    it('names production as its only server', function (): void {
        $spec = OpenApiSpec::load();

        expect($spec->document()['servers'] ?? null)->toBe([['description' => 'Production', 'url' => SpecNormalizer::PRODUCTION_SERVER]])
            ->and($spec->basePath())->toBe('/v1');
    });

    it('describes API v1 in OpenAPI 3.1', function (): void {
        $document = OpenApiSpec::load()->document();
        $info = $document['info'] ?? null;

        expect($document['openapi'] ?? null)->toBeString()->toStartWith('3.1')
            ->and(is_array($info) ? ($info['title'] ?? null) : null)->toBe('LIVCK Cloud API')
            ->and(OpenApiSpec::load()->operations())->not->toBeEmpty();
    });
});
