<?php

declare(strict_types=1);

use LIVCK\Cloud\Enums\TagSource;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Day;
use LIVCK\Cloud\Support\Json;
use LIVCK\Cloud\Support\JsonObject;
use LIVCK\Cloud\Support\KeepSecret;
use LIVCK\Cloud\Support\Translatable;

describe('decode', function (): void {
    it('accepts a JSON object or an empty array', function (): void {
        expect(Json::decode('{"a":1}'))->toBe(['a' => 1])
            ->and(Json::decode('{}'))->toBe([])
            ->and(Json::decode('[]'))->toBe([]);
    });

    it('refuses scalars, lists and malformed text', function (string $json, string $message): void {
        expect(fn(): array => Json::decode($json))->toThrow(UnexpectedResponseException::class, $message);
        expect(Json::tryDecode($json))->toBeNull();
    })->with([
        'scalar' => ['"x"', 'got string'],
        'null' => ['null', 'got null'],
        'list' => ['[1]', 'JSON array'],
        'malformed' => ['{"a":', 'not valid JSON'],
        'empty' => ['', 'not valid JSON'],
    ]);
});

describe('encode', function (): void {
    it('writes instants, days, enums and sentinels in their wire form', function (): void {
        $encoded = Json::encode([
            'starts_at' => new DateTimeImmutable('2026-08-01 14:00:00', new DateTimeZone('Europe/Berlin')),
            'day' => Day::fromString('2026-08-01'),
            'source' => TagSource::User,
            'token' => KeepSecret::keep(),
            'title' => Translatable::translations(['de' => 'Störung', 'en' => 'Outage']),
            'message' => Translatable::of('Plain'),
            'path' => 'https://example.test/a',
        ]);

        expect($encoded)->toBe('{"starts_at":"2026-08-01T12:00:00Z","day":"2026-08-01","source":"user","token":"__LIVCK_KEEP_UNCHANGED__","title":{"de":"Störung","en":"Outage"},"message":"Plain","path":"https://example.test/a"}');
    });

    it('keeps an empty map an object', function (): void {
        expect(Json::encode(['headers' => JsonObject::of([])]))->toBe('{"headers":{}}')
            ->and(Json::encode(['headers' => JsonObject::empty()]))->toBe('{"headers":{}}')
            ->and(Json::encode(['headers' => new stdClass()]))->toBe('{"headers":{}}')
            ->and(Json::encode(['headers' => JsonObject::of(['X-A' => '1'])]))->toBe('{"headers":{"X-A":"1"}}')
            ->and(Json::encode(['headers' => []]))->toBe('{"headers":[]}');
    });

    it('normalises values nested inside objects too', function (): void {
        $object = new stdClass();
        $object->at = new DateTimeImmutable('2026-08-01T00:00:00Z');

        expect(Json::encode(['config' => JsonObject::of(['when' => new DateTimeImmutable('2026-08-01T00:00:00Z')]), 'plain' => $object]))
            ->toBe('{"config":{"when":"2026-08-01T00:00:00Z"},"plain":{"at":"2026-08-01T00:00:00Z"}}');
    });

    it('preserves floats, unicode and slashes', function (): void {
        expect(Json::encode(['ratio' => 1.0, 'name' => 'Müller', 'url' => 'https://x.test/a']))
            ->toBe('{"ratio":1.0,"name":"Müller","url":"https://x.test/a"}');
    });

    it('refuses an Unrecognized enum case and values that cannot be encoded', function (): void {
        expect(fn(): string => Json::encode(['source' => TagSource::Unrecognized]))
            ->toThrow(InvalidArgumentException::class, 'Unrecognized');

        expect(fn(): string => Json::encode(['bad' => "\xB1\x31"]))
            ->toThrow(InvalidArgumentException::class, 'cannot be encoded');
    });
});
