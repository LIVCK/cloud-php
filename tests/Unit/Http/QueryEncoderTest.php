<?php

declare(strict_types=1);

use LIVCK\Cloud\Enums\TagSource;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Http\QueryEncoder;
use LIVCK\Cloud\Support\Day;

it('encodes scalars, omits nulls and percent-encodes per RFC 3986', function (): void {
    expect(QueryEncoder::encode([
        'label' => 'kunde:4711',
        'name' => 'Müller & Söhne',
        'page' => 2,
        'ratio' => 0.5,
        'absent' => null,
    ]))->toBe('label=kunde%3A4711&name=M%C3%BCller%20%26%20S%C3%B6hne&page=2&ratio=0.5');
});

it('sends booleans as 1 and 0, which is what the boolean validation rule accepts', function (): void {
    expect(QueryEncoder::encode(['resolved' => true, 'is_published' => false]))->toBe('resolved=1&is_published=0');
});

it('repeats lists as name[] and sends an empty list as an explicitly empty parameter', function (): void {
    expect(QueryEncoder::encode(['probe' => ['ffm', 'hel']]))->toBe('probe%5B%5D=ffm&probe%5B%5D=hel')
        ->and(QueryEncoder::encode(['service_ids' => []]))->toBe('service_ids=')
        ->and(QueryEncoder::encode(['service_ids' => [null]]))->toBe('service_ids=');
});

it('nests objects as name[key]', function (): void {
    expect(QueryEncoder::encode(['filter' => ['status' => 'down', 'probes' => ['ffm']]]))
        ->toBe('filter%5Bstatus%5D=down&filter%5Bprobes%5D%5B%5D=ffm');
});

it('formats instants as ISO 8601 UTC with Z, keeping a fraction only when it carries information', function (): void {
    $berlin = new DateTimeImmutable('2026-08-01 14:30:00', new DateTimeZone('Europe/Berlin'));
    $fraction = new DateTimeImmutable('2026-08-01 12:00:00.250000', new DateTimeZone('UTC'));

    expect(QueryEncoder::encode(['from' => $berlin]))->toBe('from=2026-08-01T12%3A30%3A00Z')
        ->and(QueryEncoder::encode(['from' => $fraction]))->toBe('from=2026-08-01T12%3A00%3A00.25Z');
});

it('sends a calendar day as YYYY-MM-DD', function (): void {
    expect(QueryEncoder::encode(['day' => Day::fromString('2026-08-01')]))->toBe('day=2026-08-01');
});

it('sends backed enums by value and refuses the Unrecognized case', function (): void {
    expect(QueryEncoder::encode(['source' => TagSource::System]))->toBe('source=system');

    expect(fn(): string => QueryEncoder::encode(['source' => TagSource::Unrecognized]))
        ->toThrow(InvalidArgumentException::class, 'Unrecognized');
});

it('refuses values without a query-string form', function (mixed $value, string $message): void {
    expect(fn(): string => QueryEncoder::encode(['x' => $value]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'nested list' => [[['a']], 'nested lists'],
    'object' => [new stdClass(), 'no query-string form'],
    'infinite float' => [INF, 'INF and NAN'],
]);

it('uses the string form of Stringable values', function (): void {
    $value = new class implements Stringable {
        public function __toString(): string
        {
            return 'stringable';
        }
    };

    expect(QueryEncoder::encode(['x' => $value]))->toBe('x=stringable');
});

it('encodes nothing for an empty query', function (): void {
    expect(QueryEncoder::encode([]))->toBe('')
        ->and(QueryEncoder::encode(['a' => null]))->toBe('');
});
