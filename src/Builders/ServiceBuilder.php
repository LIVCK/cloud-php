<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Builders;

use BackedEnum;
use LIVCK\Cloud\Builders\Conditions\Condition;
use LIVCK\Cloud\Data\CheckTypeCatalog;
use LIVCK\Cloud\Data\CheckTypeDefinition;
use LIVCK\Cloud\Data\ConditionField;
use LIVCK\Cloud\Data\ConditionSubtypeMap;
use LIVCK\Cloud\Data\ConfigField;
use LIVCK\Cloud\Data\IntervalBounds;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Enums\ApiEnum;
use LIVCK\Cloud\Enums\CheckType;
use LIVCK\Cloud\Enums\DnsRecordType;
use LIVCK\Cloud\Exceptions\CatalogValidationException;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\JsonObject;
use LIVCK\Cloud\Support\TagIds;

/**
 * The body of `POST /v1/services`, built per check type so that only what the type
 * accepts can be expressed:
 *
 *     $service = $client->services()->create(
 *         ServiceBuilder::http('Shop', 'https://shop.example.com/health')
 *             ->interval(60)
 *             ->probes('ffm', 'hel')
 *             ->tags($customerTag)
 *             ->header('X-Api-Key', $key)
 *             ->condition(
 *                 HttpCondition::statusCode()->gte(500)->down(),
 *                 HttpCondition::responseTimeMs()->gt(2000)->degraded(),
 *             ),
 *     );
 *
 * Builders are immutable: every setter returns a new instance, the receiver is untouched.
 *
 * A service is created with `settings` whenever the type is monitored, and `settings`
 * always carry an interval: a service without settings would exist but never be checked
 * (`is_configured: false`). The default interval is 60 seconds (6 hours for SSL, whose
 * server-side floor is hourly); the plan's minimum applies on top and is the server's to
 * enforce: an interval below it is a 422 naming `settings.interval_seconds`. The same holds
 * for the number of locations and of conditions.
 *
 * Nothing the server may accept tomorrow needs a new SDK release: {@see config()},
 * {@see setting()} and {@see attribute()} write any key, and {@see Condition::custom()}
 * any condition. They override the typed values and are sent as given.
 */
abstract class ServiceBuilder
{
    /** The interval used when a type has no default of its own and none was set. */
    public const int FALLBACK_INTERVAL_SECONDS = 60;

    /** @var list<string>|null */
    protected ?array $tags = null;

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var list<Condition> */
    protected array $conditions = [];

    /** @var array<string, mixed> */
    protected array $configOverrides = [];

    /** @var array<string, mixed> */
    protected array $settingOverrides = [];

    /** @var array<string, mixed> */
    protected array $attributeOverrides = [];

    final protected function __construct(
        protected readonly string $name,
        protected readonly CheckType $checkType,
        protected readonly ?string $target,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A service name must not be blank.');
        }
    }

    /** An HTTP(S) check of a URL (`https://…`). */
    public static function http(string $name, string $url): HttpServiceBuilder
    {
        return new HttpServiceBuilder($name, CheckType::Http, self::requireTarget($url, 'URL'));
    }

    /** A TCP connect check; the target is `host:port` (an IPv6 literal is bracketed). */
    public static function tcp(string $name, string $host, int $port): TcpServiceBuilder
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(sprintf('A TCP port lies between 1 and 65535, %d given.', $port));
        }

        $host = self::requireTarget($host, 'host');

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        return new TcpServiceBuilder($name, CheckType::Tcp, $host . ':' . $port);
    }

    /** A DNS lookup of a host name; the record type is required and defaults to `A`. */
    public static function dns(string $name, string $hostname, DnsRecordType $recordType = DnsRecordType::A): DnsServiceBuilder
    {
        return (new DnsServiceBuilder($name, CheckType::Dns, self::requireTarget($hostname, 'hostname')))->recordType($recordType);
    }

    /** An ICMP echo (ping) check of a host name or IP. */
    public static function icmp(string $name, string $host): IcmpServiceBuilder
    {
        return new IcmpServiceBuilder($name, CheckType::Icmp, self::requireTarget($host, 'host'));
    }

    /** A certificate check of a host name or IP (port 443). */
    public static function ssl(string $name, string $host): SslServiceBuilder
    {
        return new SslServiceBuilder($name, CheckType::Ssl, self::requireTarget($host, 'host'));
    }

    /**
     * A service nothing probes (a phone system, an abuse desk): it has no target, no
     * settings, and is switched by hand through the status override.
     */
    public static function manual(string $name): ManualServiceBuilder
    {
        return new ManualServiceBuilder($name, CheckType::Manual, null);
    }

    /**
     * The tags to attach, by id or Tag DTO. The API takes tag IDS only: a label such as
     * `customer:4711` is refused here, resolve it with `tags()->ensure()` first.
     */
    public function tags(Tag|string ...$tags): static
    {
        $copy = clone $this;
        $copy->tags = TagIds::of(...$tags);

        return $copy;
    }

    /**
     * Any key of `settings.config`, sent as given; overrides a typed value of the same key.
     */
    public function config(string $key, mixed $value): static
    {
        $copy = clone $this;
        $copy->configOverrides[$this->requireKey($key)] = $value;

        return $copy;
    }

    /**
     * Any key of `settings`, sent as given; overrides a typed value of the same key (a
     * `config` key here replaces the whole config block).
     */
    public function setting(string $key, mixed $value): static
    {
        $copy = clone $this;
        $copy->settingOverrides[$this->requireKey($key)] = $value;

        return $copy;
    }

    /**
     * Any top-level key of the body, sent as given; overrides a typed value of the same
     * key (`settings` here replaces the whole settings block).
     */
    public function attribute(string $key, mixed $value): static
    {
        $copy = clone $this;
        $copy->attributeOverrides[$this->requireKey($key)] = $value;

        return $copy;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function checkType(): CheckType
    {
        return $this->checkType;
    }

    public function target(): ?string
    {
        return $this->target;
    }

    /**
     * The interval that will be sent, or null when no settings go out (a manual service).
     */
    public function intervalSeconds(): ?int
    {
        $settings = $this->settings();

        if ($settings === null) {
            return null;
        }

        $interval = $settings['interval_seconds'] ?? null;

        return is_int($interval) ? $interval : null;
    }

    /**
     * Check the payload against the live catalog before sending it: every config key must
     * be a field of the check type, a select's value one of its options, a boolean field a
     * boolean, every condition field known to the type (and to its subtype, e.g. the DNS
     * record type) with an operator the field takes, and the interval within the type's
     * own range. The plan's limits stay the server's business.
     *
     * Driven by the catalog's data alone: a field or operator the server adds passes as
     * soon as the catalog lists it.
     *
     * @throws CatalogValidationException listing every problem found
     */
    public function validate(CheckTypeCatalog $catalog): void
    {
        $definition = $catalog->find($this->checkType);

        if (! $definition instanceof CheckTypeDefinition) {
            throw new CatalogValidationException([sprintf(
                'The catalog has no "%s" check type; it offers %s.',
                $this->checkType->value,
                implode(', ', $catalog->keys()),
            )]);
        }

        $config = $this->effectiveConfig();
        $problems = [...$this->configProblems($definition, $config), ...$this->conditionProblems($definition, $config)];

        $interval = $this->intervalSeconds();

        if ($definition->interval instanceof IntervalBounds && $interval !== null && ! $definition->interval->allows($interval)) {
            $problems[] = sprintf(
                'The interval of %d seconds lies outside the range of %s checks (%s to %s seconds).',
                $interval,
                $definition->key,
                $definition->interval->min ?? 'any',
                $definition->interval->max ?? 'any',
            );
        }

        if ($problems !== []) {
            throw new CatalogValidationException($problems);
        }
    }

    /**
     * The body of `POST /v1/services`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['name' => $this->name, 'check_type' => $this->checkType->value];

        if ($this->target !== null) {
            $payload['target'] = $this->target;
        }

        if ($this->tags !== null) {
            $payload['tags'] = $this->tags;
        }

        $settings = $this->settings();

        if ($settings !== null) {
            $payload['settings'] = $settings;
        }

        return [...$payload, ...$this->attributeOverrides];
    }

    /**
     * The type's default interval; null when the type sends no settings of its own.
     */
    protected function defaultIntervalSeconds(): ?int
    {
        return null;
    }

    /**
     * The typed `settings` keys (interval, timeout, retries, locations, roles); empty for a
     * type without them.
     *
     * @return array<string, mixed>
     */
    protected function typedSettings(): array
    {
        return [];
    }

    final protected function withConfigValue(string $key, mixed $value): static
    {
        $copy = clone $this;
        $copy->config[$key] = $value;

        return $copy;
    }

    final protected function withConditions(Condition ...$conditions): static
    {
        $copy = clone $this;
        $copy->conditions = [...$this->conditions, ...array_values($conditions)];

        return $copy;
    }

    /**
     * An enum's wire value; the `Unrecognized` case has none.
     */
    final protected static function wireValue(ApiEnum&BackedEnum $value): string
    {
        if ($value->isUnrecognized()) {
            throw new InvalidArgumentException(sprintf('%s::Unrecognized stands for a value this SDK version does not know and cannot be sent.', $value::class));
        }

        return (string) $value->value;
    }

    /**
     * The `settings` block, or null when nothing is to be sent.
     *
     * @return array<string, mixed>|null
     */
    private function settings(): ?array
    {
        $settings = $this->typedSettings();
        $config = $this->effectiveConfig();

        if ($this->conditions !== []) {
            $config['conditions'] = array_map(static fn(Condition $condition): array => $condition->toArray(), $this->conditions);
        }

        if (array_key_exists('conditions', $this->configOverrides)) {
            $config['conditions'] = $this->configOverrides['conditions'];
        }

        if ($config !== []) {
            $settings['config'] = $config;
        }

        $settings = [...$settings, ...$this->settingOverrides];

        if ($settings === []) {
            return null;
        }

        if (! array_key_exists('interval_seconds', $settings)) {
            return ['interval_seconds' => $this->defaultIntervalSeconds() ?? self::FALLBACK_INTERVAL_SECONDS, ...$settings];
        }

        return $settings;
    }

    /**
     * The typed config with the overrides applied, conditions left out.
     *
     * @return array<string, mixed>
     */
    private function effectiveConfig(): array
    {
        $overrides = $this->configOverrides;
        unset($overrides['conditions']);

        return [...$this->config, ...$overrides];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function configProblems(CheckTypeDefinition $definition, array $config): array
    {
        $problems = [];

        foreach ($config as $key => $value) {
            $field = $definition->field($key);

            if (! $field instanceof ConfigField) {
                $problems[] = sprintf(
                    'Unknown config key "%s" for %s checks; the catalog knows %s.',
                    $key,
                    $definition->key,
                    $definition->fields === [] ? 'no config keys' : implode(', ', $definition->fieldNames()),
                );

                continue;
            }

            if ($field->isSelect() && ! $field->allowsOption($value)) {
                $problems[] = sprintf(
                    '%s is not an option of config.%s; the catalog offers %s.',
                    $this->describe($value),
                    $key,
                    implode(', ', array_map($this->describe(...), $field->options ?? [])),
                );
            }

            if ($field->isBoolean() && ! is_bool($value)) {
                $problems[] = sprintf('config.%s takes a boolean, %s given.', $key, get_debug_type($value));
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function conditionProblems(CheckTypeDefinition $definition, array $config): array
    {
        $subtype = $this->subtype($definition, $config);
        $fields = $definition->conditions->fieldsFor($subtype);
        $scope = $subtype === null ? sprintf('%s checks', $definition->key) : sprintf('%s checks of type %s', $definition->key, $subtype);
        $problems = [];

        foreach ($this->conditionsToValidate() as $condition) {
            $field = $this->matchingField($fields, $condition['field']);

            if (! $field instanceof ConditionField) {
                $problems[] = sprintf(
                    'Unknown condition field "%s" for %s; the catalog knows %s.',
                    $condition['field'],
                    $scope,
                    $fields === [] ? 'no condition fields' : implode(', ', array_map(static fn(ConditionField $field): string => $field->field, $fields)),
                );

                continue;
            }

            if (! $field->allows($condition['operator'])) {
                $problems[] = sprintf(
                    'Operator "%s" is not valid for the condition field "%s"; the catalog allows %s.',
                    $condition['operator'],
                    $condition['field'],
                    implode(', ', $field->operators),
                );
            }
        }

        return $problems;
    }

    /**
     * The value of the subtype field (`dns_type`) that decides which condition fields
     * apply: what was set, else the catalog's default for it. Null for types without one.
     *
     * @param array<string, mixed> $config
     */
    private function subtype(CheckTypeDefinition $definition, array $config): ?string
    {
        $map = $definition->conditions->bySubtype;

        if (! $map instanceof ConditionSubtypeMap) {
            return null;
        }

        $value = $config[$map->field] ?? $definition->field($map->field)?->default;

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Typed conditions and, when the `conditions` override carries rows with a field and
     * an operator, those as well.
     *
     * @return list<array{field: string, operator: string}>
     */
    private function conditionsToValidate(): array
    {
        $rows = array_map(
            static fn(Condition $condition): array => ['field' => $condition->field, 'operator' => $condition->operator],
            $this->conditions,
        );

        $override = $this->configOverrides['conditions'] ?? null;

        if (! is_array($override)) {
            return $rows;
        }

        foreach ($override as $row) {
            if (is_array($row) && is_string($row['field'] ?? null) && is_string($row['operator'] ?? null)) {
                $rows[] = ['field' => $row['field'], 'operator' => $row['operator']];
            }
        }

        return $rows;
    }

    /**
     * @param list<ConditionField> $fields
     */
    private function matchingField(array $fields, string $name): ?ConditionField
    {
        foreach ($fields as $field) {
            if ($field->matches($name)) {
                return $field;
            }
        }

        return null;
    }

    private function describe(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof JsonObject) {
            return '{…}';
        }

        return match (true) {
            is_string($value) => '"' . $value . '"',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => get_debug_type($value),
        };
    }

    private static function requireTarget(string $target, string $what): string
    {
        $target = trim($target);

        if ($target === '') {
            throw new InvalidArgumentException(sprintf('The %s must not be blank.', $what));
        }

        return $target;
    }

    private function requireKey(string $key): string
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A key must not be blank.');
        }

        return $key;
    }
}
