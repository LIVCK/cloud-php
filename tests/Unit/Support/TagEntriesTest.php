<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\TagEntries;

it('sends a Tag as its id, ids and names as given, trimmed, each once', function (): void {
    $tag = Tag::fromArray(tagPayload());

    expect(TagEntries::of($tag, ' customer:4711 ', 'env=prod', 'critical', 'W2TuHYS9a6keIj7CnzU12', $tag->id, 'customer:4711'))
        ->toBe([$tag->id, 'customer:4711', 'env=prod', 'critical', 'W2TuHYS9a6keIj7CnzU12']);
});

it('leaves reading an entry to the server', function (): void {
    // `a:b`, `a=b` and `A:b` are one tag to the server, which counts it once; the client does not
    // second-guess the grammar, and names that look like other systems' ids are names.
    expect(TagEntries::of('a:b', 'a=b', 'A:b', '550e8400-e29b-41d4-a716-446655440000', '1234567890123456789'))
        ->toBe(['a:b', 'a=b', 'A:b', '550e8400-e29b-41d4-a716-446655440000', '1234567890123456789']);
});

it('sends an empty list for no tags', function (): void {
    expect(TagEntries::of())->toBe([]);
});

it('refuses a blank entry', function (string $blank): void {
    expect(fn(): array => TagEntries::of('customer:4711', $blank))->toThrow(InvalidArgumentException::class, 'must not be blank');
})->with([
    'empty' => [''],
    'spaces' => ['   '],
    'tab and newline' => ["\t\n"],
]);
