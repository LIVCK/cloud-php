<?php

declare(strict_types=1);

use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Day;
use LIVCK\Cloud\Support\Field;

$data = [
    'name' => 'Web',
    'count' => 3,
    'ratio' => 0.5,
    'whole' => 2,
    'on' => true,
    'nothing' => null,
    'at' => '2026-08-01T12:00:00+00:00',
    'day' => '2026-08-01',
    'meta' => ['a' => 1],
    'items' => [['id' => 'x'], ['id' => 'y']],
    'names' => ['a', 'b'],
];

it('reads scalars of the expected type', function () use ($data): void {
    expect(Field::string($data, 'name'))->toBe('Web')
        ->and(Field::nullableString($data, 'nothing'))->toBeNull()
        ->and(Field::nullableString($data, 'missing'))->toBeNull()
        ->and(Field::int($data, 'count'))->toBe(3)
        ->and(Field::nullableInt($data, 'nothing'))->toBeNull()
        ->and(Field::float($data, 'ratio'))->toBe(0.5)
        ->and(Field::float($data, 'whole'))->toBe(2.0)
        ->and(Field::nullableFloat($data, 'nothing'))->toBeNull()
        ->and(Field::bool($data, 'on'))->toBeTrue()
        ->and(Field::nullableBool($data, 'nothing'))->toBeNull();
});

it('reads instants, days, objects and lists', function () use ($data): void {
    expect(Field::instant($data, 'at')->format(DATE_ATOM))->toBe('2026-08-01T12:00:00+00:00')
        ->and(Field::nullableInstant($data, 'nothing'))->toBeNull()
        ->and(Field::day($data, 'day')->toString())->toBe('2026-08-01')
        ->and(Field::nullableDay($data, 'nothing'))->toBeNull()
        ->and(Field::object($data, 'meta'))->toBe(['a' => 1])
        ->and(Field::nullableObject($data, 'nothing'))->toBeNull()
        ->and(Field::objectList($data, 'items'))->toBe([['id' => 'x'], ['id' => 'y']])
        ->and(Field::stringList($data, 'names'))->toBe(['a', 'b'])
        ->and(Field::list($data, 'missing'))->toBe([]);
});

it('fails with the field name and both types', function (Closure $read, string $message): void {
    expect($read)->toThrow(UnexpectedResponseException::class, $message);
})->with([
    'string got int' => [fn(): string => Field::string($data, 'count'), 'Field "count": expected string, got int'],
    'string missing' => [fn(): string => Field::string($data, 'missing'), 'expected string, got null'],
    'int got string' => [fn(): int => Field::int($data, 'name'), 'expected integer, got string'],
    'float got string' => [fn(): float => Field::float($data, 'name'), 'expected number, got string'],
    'bool got int' => [fn(): bool => Field::bool($data, 'count'), 'expected boolean, got int'],
    'instant malformed' => [fn(): DateTimeImmutable => Field::instant($data, 'name'), 'expected ISO 8601 instant, got string'],
    'instant missing' => [fn(): DateTimeImmutable => Field::instant($data, 'missing'), 'expected ISO 8601 instant, got null'],
    'day malformed' => [fn(): Day => Field::day($data, 'at'), 'expected calendar day (YYYY-MM-DD), got string'],
    'object got list' => [fn(): array => Field::object($data, 'names'), 'expected object, got array'],
    'list got object' => [fn(): array => Field::list($data, 'meta'), 'expected array, got array'],
    'object list member' => [fn(): array => Field::objectList($data, 'names'), 'Field "names[0]": expected object, got string'],
    'string list member' => [fn(): array => Field::stringList($data, 'items'), 'Field "items[0]": expected string, got array'],
]);
