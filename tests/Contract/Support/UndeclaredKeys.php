<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use stdClass;

/**
 * Finds keys of a JSON value that the schema does not declare. JSON Schema only rejects
 * unknown keys where `additionalProperties: false` is set, so a misspelled field name in a
 * request, or a fixture field the API does not send, would otherwise pass validation. Here
 * every object schema with `properties` is treated as closed unless it declares
 * `additionalProperties`; composites (`anyOf`, `oneOf`) are judged by their closest branch.
 */
final readonly class UndeclaredKeys
{
    public function __construct(
        private OpenApiSpec $spec,
    ) {}

    /**
     * @param array<string, mixed> $schema
     * @return list<string> problems as `location: message`
     */
    public function in(mixed $data, array $schema, string $location = ''): array
    {
        $schema = $this->spec->resolve($schema);
        $all = $this->branches($schema, 'allOf');

        if ($all !== null) {
            $problems = [];

            foreach ($all as $branch) {
                $problems = [...$problems, ...$this->in($data, $branch, $location)];
            }

            return $problems;
        }

        $alternatives = $this->branches($schema, 'anyOf') ?? $this->branches($schema, 'oneOf');

        if ($alternatives !== null) {
            $closest = null;

            foreach ($alternatives as $branch) {
                $problems = $this->in($data, $branch, $location);

                if ($problems === []) {
                    return [];
                }

                if ($closest === null || count($problems) < count($closest)) {
                    $closest = $problems;
                }
            }

            return $closest ?? [];
        }

        if ($data instanceof stdClass) {
            return $this->inObject($data, $schema, $location);
        }

        if (is_array($data)) {
            $items = $schema['items'] ?? null;

            if (! is_array($items) || $items === [] || array_is_list($items)) {
                return [];
            }

            /** @var array<string, mixed> $items */
            $problems = [];

            foreach ($data as $index => $item) {
                $problems = [...$problems, ...$this->in($item, $items, $location . '/' . $index)];
            }

            return $problems;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private function inObject(stdClass $data, array $schema, string $location): array
    {
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties)) {
            return [];
        }

        $additional = $schema['additionalProperties'] ?? null;
        $title = is_string($schema['title'] ?? null) ? ' ' . $schema['title'] : '';
        $problems = [];

        foreach (get_object_vars($data) as $key => $value) {
            $key = (string) $key;

            if (array_key_exists($key, $properties)) {
                $property = $properties[$key];

                /** @var array<string, mixed> $property */
                $property = is_array($property) ? $property : [];
                $problems = [...$problems, ...$this->in($value, $property, $location . '/' . $key)];

                continue;
            }

            if ($additional === null || $additional === false) {
                $problems[] = sprintf(
                    '%s: "%s" is not a property the schema%s declares (declared: %s)',
                    $location === '' ? '/' : $location,
                    $key,
                    $title,
                    implode(', ', array_map(strval(...), array_keys($properties))),
                );

                continue;
            }

            if (is_array($additional) && ! array_is_list($additional)) {
                /** @var array<string, mixed> $additional */
                $problems = [...$problems, ...$this->in($value, $additional, $location . '/' . $key)];
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<array<string, mixed>>|null
     */
    private function branches(array $schema, string $keyword): ?array
    {
        $branches = $schema[$keyword] ?? null;

        if (! is_array($branches) || $branches === []) {
            return null;
        }

        $result = [];

        foreach ($branches as $branch) {
            if (is_array($branch)) {
                /** @var array<string, mixed> $branch */
                $result[] = $branch;
            }
        }

        return $result;
    }
}
