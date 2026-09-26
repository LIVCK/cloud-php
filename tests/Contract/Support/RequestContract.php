<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use JsonException;
use LIVCK\Cloud\Testing\RecordedRequest;

/**
 * Checks one request the client sent against the API document: the operation must
 * exist, every query parameter must be declared and hold a valid value, the
 * Idempotency-Key must be declared where it is sent, and the body must match the
 * declared request schema (JSON) or part names (multipart).
 */
final readonly class RequestContract
{
    /**
     * The client sends an Idempotency-Key on every POST. The asset upload is the one POST
     * the document does not declare it for: the server's idempotency middleware skips that
     * route (a multipart body is not fingerprinted) and ignores the header. Sending it is
     * harmless, so it is tolerated here instead of being reported as drift.
     */
    private const array IDEMPOTENCY_KEY_TOLERATED = ['POST /statuspages/{statuspage}/assets/{asset}'];

    private const string IDEMPOTENCY_KEY = 'Idempotency-Key';

    private UndeclaredKeys $undeclaredKeys;

    public function __construct(
        private OpenApiSpec $spec,
    ) {
        $this->undeclaredKeys = new UndeclaredKeys($spec);
    }

    public function operationFor(RecordedRequest $request): ?Operation
    {
        return $this->spec->find($request->method, $request->path());
    }

    /**
     * @return list<string> problems, each prefixed with the operation; empty when the request fits
     */
    public function problems(RecordedRequest $request): array
    {
        $operation = $this->operationFor($request);

        if (! $operation instanceof Operation) {
            return [sprintf('%s %s matches no operation of the API document.', $request->method, $request->path())];
        }

        $problems = [
            ...$this->queryProblems($request, $operation),
            ...$this->headerProblems($request, $operation),
            ...$this->bodyProblems($request, $operation),
        ];

        return array_map(static fn(string $problem): string => $operation->key() . ': ' . $problem, $problems);
    }

    /**
     * @return list<string>
     */
    private function queryProblems(RecordedRequest $request, Operation $operation): array
    {
        $problems = [];

        foreach (QueryString::parse($request->queryString()) as $sent) {
            $parameter = $operation->queryParameter($sent->baseName);

            if (! $parameter instanceof Parameter) {
                $declared = $operation->queryParameterNames();
                $problems[] = sprintf(
                    'query parameter "%s" is not declared%s',
                    $sent->name,
                    $declared === [] ? ' (the operation takes none)' : ' (declared: ' . implode(', ', $declared) . ')',
                );

                continue;
            }

            if ($sent->isList && ! $parameter->isList()) {
                $problems[] = sprintf('query parameter "%s" is sent as a list but declared as a scalar', $sent->name);
            }

            foreach ($this->valueProblems($sent, $parameter) as $problem) {
                $problems[] = sprintf('query parameter "%s" %s', $sent->name, $problem);
            }
        }

        return $problems;
    }

    /**
     * A scalar is validated against its schema. The items of a repeatable parameter are
     * validated one by one against `items`: the server's document writer copies the item constraints
     * (`enum`, `pattern`) onto the array schema as well, where JSON Schema would apply them
     * to the array as a whole and reject every list.
     *
     * @return list<string>
     */
    private function valueProblems(QueryValue $sent, Parameter $parameter): array
    {
        $value = $this->coerce($sent, $parameter);

        if (! $parameter->isList() || ! is_array($value)) {
            return $this->spec->validate($value, $parameter->schemaPointer);
        }

        if ($parameter->itemSchema() === []) {
            return [];
        }

        $problems = [];

        foreach ($value as $index => $item) {
            foreach ($this->spec->validate($item, $parameter->schemaPointer . '/items') as $problem) {
                $problems[] = sprintf('[%d] %s', $index, $problem);
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function headerProblems(RecordedRequest $request, Operation $operation): array
    {
        $key = $request->header(self::IDEMPOTENCY_KEY);

        if ($key === null) {
            return [];
        }

        $parameter = $operation->headerParameter(self::IDEMPOTENCY_KEY);

        if (! $parameter instanceof Parameter) {
            return in_array($operation->key(), self::IDEMPOTENCY_KEY_TOLERATED, true)
                ? []
                : [sprintf('sends an %s header the operation does not declare', self::IDEMPOTENCY_KEY)];
        }

        return array_map(
            static fn(string $problem): string => sprintf('%s header %s', self::IDEMPOTENCY_KEY, $problem),
            $this->spec->validate($key, $parameter->schemaPointer),
        );
    }

    /**
     * @return list<string>
     */
    private function bodyProblems(RecordedRequest $request, Operation $operation): array
    {
        $contentType = strtolower((string) $request->header('Content-Type'));
        $declared = $operation->requestBody;

        if ($request->body === '') {
            return $declared instanceof RequestBody && $declared->required ? ['requires a request body, none was sent'] : [];
        }

        if (! $declared instanceof RequestBody) {
            return [sprintf('sends a %s body, the operation declares none', $contentType === '' ? 'raw' : $contentType)];
        }

        if (str_starts_with($contentType, 'application/json')) {
            return $this->jsonBodyProblems($request->body, $declared);
        }

        if (str_starts_with($contentType, 'multipart/form-data')) {
            return $this->multipartProblems($request->body, $declared);
        }

        return [sprintf('sends a body of type "%s", the operation declares %s', $contentType, implode(', ', $declared->contentTypes()))];
    }

    /**
     * @return list<string>
     */
    private function jsonBodyProblems(string $json, RequestBody $declared): array
    {
        $pointer = $declared->pointerFor('application/json');

        if ($pointer === null) {
            return [sprintf('sends JSON, the operation declares %s', implode(', ', $declared->contentTypes()))];
        }

        try {
            $data = OpenApiSpec::decode($json);
        } catch (JsonException $e) {
            return [sprintf('body is not valid JSON: %s', $e->getMessage())];
        }

        $problems = [
            ...$this->spec->validate($data, $pointer),
            ...$this->undeclaredKeys->in($data, $this->spec->schemaAt($pointer)),
        ];

        return array_map(static fn(string $problem): string => 'body ' . $problem, $problems);
    }

    /**
     * @return list<string>
     */
    private function multipartProblems(string $body, RequestBody $declared): array
    {
        $pointer = $declared->pointerFor('multipart/form-data');

        if ($pointer === null) {
            return [sprintf('sends multipart/form-data, the operation declares %s', implode(', ', $declared->contentTypes()))];
        }

        $schema = $this->spec->schemaAt($pointer);
        $properties = is_array($schema['properties'] ?? null) ? array_map(strval(...), array_keys($schema['properties'])) : [];
        $required = is_array($schema['required'] ?? null) ? array_filter($schema['required'], is_string(...)) : [];
        $names = Multipart::partNames($body);
        $problems = [];

        if ($names === []) {
            $problems[] = 'multipart body carries no part';
        }

        foreach ($names as $name) {
            if (! in_array($name, $properties, true)) {
                $problems[] = sprintf('multipart part "%s" is not declared (declared: %s)', $name, implode(', ', $properties));
            }
        }

        foreach ($required as $name) {
            if (! in_array($name, $names, true)) {
                $problems[] = sprintf('required multipart part "%s" is missing', $name);
            }
        }

        return $problems;
    }

    /**
     * A query value in the JSON type its parameter declares, the way the server reads it:
     * `1`/`0` as booleans, digits as integers, repeated `name[]` as a list. `name=` without
     * a value is the client's explicit empty list.
     */
    private function coerce(QueryValue $sent, Parameter $parameter): mixed
    {
        if ($parameter->isList()) {
            $values = $sent->isList || $sent->scalar() !== '' ? $sent->values : [];
            $itemType = $parameter->itemSchema()['type'] ?? 'string';

            return array_map(fn(string $value): mixed => $this->scalar($value, $itemType), $values);
        }

        return $this->scalar($sent->scalar(), $parameter->schema['type'] ?? 'string');
    }

    private function scalar(string $value, mixed $type): mixed
    {
        $types = is_array($type) ? $type : [$type];

        if (in_array('integer', $types, true) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (in_array('number', $types, true) && is_numeric($value)) {
            return (float) $value;
        }

        if (in_array('boolean', $types, true)) {
            if (in_array($value, ['1', 'true'], true)) {
                return true;
            }

            if (in_array($value, ['0', 'false'], true)) {
                return false;
            }
        }

        return $value;
    }
}
