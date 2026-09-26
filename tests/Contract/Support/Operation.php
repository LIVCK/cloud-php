<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * One operation of the API document: a method and a path template with what it declares.
 */
final readonly class Operation
{
    /**
     * Update routes the server registers as `PUT|PATCH` (Laravel's resource routing; both
     * apply the same partial update) while the published document lists `PUT` only. The
     * client sends PATCH to these; `php artisan route:list --path=api/v1` shows both verbs.
     */
    public const array PATCH_ALIASES = [
        '/tags/{tag}',
        '/services/{service}',
        '/statuspages/{statuspage}',
        '/statuspages/{statuspage}/components/{component}',
    ];

    /**
     * @param list<string> $tags
     * @param list<Parameter> $parameters
     * @param array<int|string, ResponseSpec> $responses keyed by status code (PHP stores "200" as 200)
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $id,
        public array $tags,
        public array $parameters,
        public ?RequestBody $requestBody,
        public array $responses,
    ) {}

    /** `GET /tags/{tag}` */
    public function key(): string
    {
        return $this->method . ' ' . $this->path;
    }

    public function acceptsMethod(string $method): bool
    {
        $method = strtoupper($method);

        if ($this->method === $method) {
            return true;
        }

        return $method === 'PATCH' && $this->method === 'PUT' && in_array($this->path, self::PATCH_ALIASES, true);
    }

    /** Whether a concrete path (without the server's base path) fits the template. */
    public function matchesPath(string $path): bool
    {
        return preg_match($this->pattern(), $path) === 1;
    }

    /** How many path segments are literal; the more, the more specific the template. */
    public function literalSegments(): int
    {
        return count(array_filter(
            explode('/', trim($this->path, '/')),
            static fn(string $segment): bool => ! str_starts_with($segment, '{'),
        ));
    }

    public function queryParameter(string $baseName): ?Parameter
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->in === 'query' && $parameter->baseName() === $baseName) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function queryParameterNames(): array
    {
        $names = [];

        foreach ($this->parameters as $parameter) {
            if ($parameter->in === 'query') {
                $names[] = $parameter->name;
            }
        }

        return $names;
    }

    public function headerParameter(string $name): ?Parameter
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->in === 'header' && strcasecmp($parameter->name, $name) === 0) {
                return $parameter;
            }
        }

        return null;
    }

    public function response(int|string $status): ?ResponseSpec
    {
        return $this->responses[$status] ?? null;
    }

    private function pattern(): string
    {
        $segments = array_map(
            static fn(string $segment): string => str_starts_with($segment, '{') ? '[^/]+' : preg_quote($segment, '~'),
            explode('/', trim($this->path, '/')),
        );

        return '~^/' . implode('/', $segments) . '$~';
    }
}
