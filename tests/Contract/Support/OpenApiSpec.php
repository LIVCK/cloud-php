<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * The vendored OpenAPI 3.1 document of the API, loaded once per process: its operations
 * by method and path template, and JSON Schema validation of any value against any
 * schema in it (request bodies, responses, parameters), with `#/components/...`
 * references resolving inside the document.
 */
final class OpenApiSpec
{
    public const string PATH = __DIR__ . '/../../Fixtures/openapi/livck-cloud-v1.json';

    /** The id the document is registered under; `$ref`s are resolved against it. */
    private const string DOCUMENT_ID = 'https://api.livck.cloud/openapi/livck-cloud-v1.json';

    private const array METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    private static ?self $loaded = null;

    private readonly Validator $validator;

    /** @var array<string, Operation> keyed by `METHOD /path` */
    private readonly array $operations;

    private readonly string $basePath;

    /**
     * @param array<string, mixed> $document
     */
    private function __construct(
        private readonly array $document,
        string $json,
    ) {
        $validator = new Validator();
        $validator->setMaxErrors(25);
        $resolver = $validator->resolver();

        if (! $resolver instanceof SchemaResolver) {
            throw new RuntimeException('The JSON Schema validator has no resolver to register the document with.');
        }

        $resolver->registerRaw($json, self::DOCUMENT_ID);

        $this->validator = $validator;
        $this->basePath = $this->basePathOf($document);
        $this->operations = $this->buildOperations($document);
    }

    public static function load(): self
    {
        return self::$loaded ??= self::fromFile(self::PATH);
    }

    public static function fromFile(string $path): self
    {
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException(sprintf('Cannot read the API document at %s.', $path));
        }

        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($document)) {
            throw new InvalidArgumentException('The API document is not a JSON object.');
        }

        /** @var array<string, mixed> $document */
        return new self($document, $json);
    }

    /** JSON decoded the way the validator expects it: objects as objects. */
    public static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->document;
    }

    /** The path of the server URL (`/v1`), which every request path starts with. */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * @return array<string, Operation> keyed by `METHOD /path`
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function operation(string $key): Operation
    {
        return $this->operations[$key] ?? throw new InvalidArgumentException(sprintf('The API document has no operation "%s".', $key));
    }

    /**
     * The operation a concrete request hits: the most specific template the path fits
     * (`/tags/ensure` before `/tags/{tag}`), null when none does.
     *
     * @param string $path the request path including the base path, e.g. `/v1/tags/abc`
     */
    public function find(string $method, string $path): ?Operation
    {
        if (! str_starts_with($path, $this->basePath . '/')) {
            return null;
        }

        $relative = substr($path, strlen($this->basePath));
        $best = null;

        foreach ($this->operations as $operation) {
            if (! $operation->acceptsMethod($method) || ! $operation->matchesPath($relative)) {
                continue;
            }

            if (! $best instanceof Operation || $operation->literalSegments() > $best->literalSegments()) {
                $best = $operation;
            }
        }

        return $best;
    }

    /**
     * Validate a decoded JSON value against the schema at a pointer of the document.
     *
     * @return list<string> problems as `location: message`, empty when valid
     */
    public function validate(mixed $data, string $pointer): array
    {
        $result = $this->validator->validate($data, self::DOCUMENT_ID . '#' . JsonPointer::fragment($pointer));
        $error = $result->error();

        if (! $error instanceof ValidationError) {
            return [];
        }

        $problems = [];

        foreach ((new ErrorFormatter())->format($error) as $location => $messages) {
            $location = (string) $location;

            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                $message = is_string($message) ? $message : 'invalid';

                if ($this->isNullInNullableEnum($message, $data, $this->schemaAt($pointer), $location)) {
                    continue;
                }

                $problems[] = sprintf('%s: %s', $location, $message);
            }
        }

        // A composite (`anyOf`) repeats the same leaf error once per branch.
        return array_values(array_unique($problems));
    }

    /**
     * The server's document writer describes a nullable field with a fixed value set as `type: [string, null]`
     * plus an `enum` that lists the values but not `null`, although an enum constrains every
     * type. A null the type allows is not drift; the enum's gap is the document's.
     *
     * @param array<string, mixed> $schema
     */
    private function isNullInNullableEnum(string $message, mixed $data, array $schema, string $location): bool
    {
        if (! str_contains($message, 'enum') || $this->valueAt($data, $location) !== null) {
            return false;
        }

        $target = $this->schemaForInstance($schema, JsonPointer::tokens($location));
        $type = $target['type'] ?? null;
        $enum = $target['enum'] ?? null;

        return is_array($type) && in_array('null', $type, true) && is_array($enum) && ! in_array(null, $enum, true);
    }

    /**
     * The schema that applies to a location of the data, walking `properties` and `items`.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $tokens
     * @return array<string, mixed>
     */
    private function schemaForInstance(array $schema, array $tokens): array
    {
        foreach ($tokens as $token) {
            $schema = $this->resolve($schema);
            $properties = $schema['properties'] ?? null;
            $next = is_array($properties) && is_array($properties[$token] ?? null) ? $properties[$token] : ($schema['items'] ?? null);

            if (! is_array($next)) {
                return [];
            }

            /** @var array<string, mixed> $next */
            $schema = $next;
        }

        return $this->resolve($schema);
    }

    private function valueAt(mixed $data, string $location): mixed
    {
        foreach (JsonPointer::tokens($location) as $token) {
            if ($data instanceof stdClass) {
                $data = get_object_vars($data)[$token] ?? null;
            } elseif (is_array($data)) {
                $data = $data[$token] ?? null;
            } else {
                return null;
            }
        }

        return $data;
    }

    /**
     * The schema at a pointer with its `$ref` chain followed; empty when there is none.
     *
     * @return array<string, mixed>
     */
    public function schemaAt(string $pointer): array
    {
        $node = $this->nodeAt($pointer);

        if (! is_array($node)) {
            return [];
        }

        /** @var array<string, mixed> $node */
        return $this->resolve($node);
    }

    /**
     * Follow `$ref` chains inside the document; keywords next to a `$ref` (a description)
     * stay on top of the target.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function resolve(array $schema): array
    {
        for ($depth = 0; $depth < 10; $depth++) {
            $ref = $schema['$ref'] ?? null;

            if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
                return $schema;
            }

            $target = $this->nodeAt(substr($ref, 1));

            if (! is_array($target)) {
                throw new InvalidArgumentException(sprintf('The API document has no schema at %s.', $ref));
            }

            unset($schema['$ref']);

            /** @var array<string, mixed> $target */
            $schema = [...$target, ...$schema];
        }

        return $schema;
    }

    private function nodeAt(string $pointer): mixed
    {
        $node = $this->document;

        foreach (JsonPointer::tokens($pointer) as $token) {
            if (! is_array($node) || ! array_key_exists($token, $node)) {
                return null;
            }

            $node = $node[$token];
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function basePathOf(array $document): string
    {
        $servers = $document['servers'] ?? null;
        $url = is_array($servers) && is_array($servers[0] ?? null) ? ($servers[0]['url'] ?? null) : null;

        if (! is_string($url)) {
            throw new InvalidArgumentException('The API document declares no server URL.');
        }

        return rtrim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, Operation>
     */
    private function buildOperations(array $document): array
    {
        $paths = $document['paths'] ?? null;

        if (! is_array($paths)) {
            throw new InvalidArgumentException('The API document declares no paths.');
        }

        $operations = [];

        foreach ($paths as $path => $item) {
            $path = (string) $path;

            if (! is_array($item)) {
                continue;
            }

            $pathParameters = is_array($item['parameters'] ?? null) ? $item['parameters'] : [];

            foreach (self::METHODS as $method) {
                $raw = $item[$method] ?? null;

                if (! is_array($raw)) {
                    continue;
                }

                /** @var array<string, mixed> $raw */
                $operation = $this->buildOperation($path, $method, $raw, $pathParameters);
                $operations[$operation->key()] = $operation;
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<array-key, mixed> $pathParameters
     */
    private function buildOperation(string $path, string $method, array $raw, array $pathParameters): Operation
    {
        $parameters = [];

        foreach ($pathParameters as $index => $parameter) {
            if (is_array($parameter)) {
                /** @var array<string, mixed> $parameter */
                $parameters[] = Parameter::fromArray($parameter, JsonPointer::of('paths', $path, 'parameters', (string) $index, 'schema'));
            }
        }

        $ownParameters = is_array($raw['parameters'] ?? null) ? $raw['parameters'] : [];

        foreach ($ownParameters as $index => $parameter) {
            if (is_array($parameter)) {
                /** @var array<string, mixed> $parameter */
                $parameters[] = Parameter::fromArray($parameter, JsonPointer::of('paths', $path, $method, 'parameters', (string) $index, 'schema'));
            }
        }

        $requestBody = null;
        $rawBody = $raw['requestBody'] ?? null;

        if (is_array($rawBody)) {
            $pointers = [];
            $content = is_array($rawBody['content'] ?? null) ? $rawBody['content'] : [];

            foreach (array_keys($content) as $contentType) {
                $pointers[(string) $contentType] = JsonPointer::of('paths', $path, $method, 'requestBody', 'content', (string) $contentType, 'schema');
            }

            $requestBody = new RequestBody((bool) ($rawBody['required'] ?? false), $pointers);
        }

        $responses = [];
        $rawResponses = is_array($raw['responses'] ?? null) ? $raw['responses'] : [];

        foreach ($rawResponses as $status => $response) {
            if (! is_array($response)) {
                continue;
            }

            $base = JsonPointer::of('paths', $path, $method, 'responses', (string) $status);
            $ref = $response['$ref'] ?? null;

            if (is_string($ref) && str_starts_with($ref, '#/')) {
                $base = substr($ref, 1);
                $response = $this->nodeAt($base);
            }

            $pointers = [];
            $content = is_array($response) && is_array($response['content'] ?? null) ? $response['content'] : [];

            foreach (array_keys($content) as $contentType) {
                $pointers[(string) $contentType] = $base . JsonPointer::of('content', (string) $contentType, 'schema');
            }

            // The decoded document keys numeric statuses as integers ("200" is 200).
            $responses[$status] = new ResponseSpec((string) $status, $pointers);
        }

        $tags = is_array($raw['tags'] ?? null) ? array_values(array_filter($raw['tags'], is_string(...))) : [];
        $id = is_string($raw['operationId'] ?? null) ? $raw['operationId'] : '';

        return new Operation(
            strtoupper($method),
            $path,
            $id,
            $tags,
            $parameters,
            $requestBody,
            $responses,
        );
    }
}
