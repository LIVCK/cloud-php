<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Data;

use LIVCK\Cloud\Data\Concerns\RedactsSecrets;
use LIVCK\Cloud\Enums\HeartbeatDeclarationOutcome;
use LIVCK\Cloud\Enums\HeartbeatKind;
use LIVCK\Cloud\Enums\ServiceStatus;
use LIVCK\Cloud\Exceptions\UnexpectedResponseException;
use LIVCK\Cloud\Support\Field;
use LIVCK\Cloud\Support\Secret;
use SensitiveParameter;

/**
 * One heartbeat of a declaration as it exists after the call, with what the call did to it.
 *
 * The schedule, grace, tolerance and maximum run time are the values in effect, which can differ
 * from what was declared: a period below the plan's shortest is raised, and a field the
 * declaration left out keeps the value it has in LIVCK. The name is set when the heartbeat is
 * created and never changed by a later declaration.
 *
 * `pingUrl` is where the job reports to ({@see \LIVCK\Cloud\Ping\HeartbeatPing}). Whoever has it
 * can report for the job, so it is a {@see Secret}: `reveal()` reads it, dumps, logs and
 * `json_encode()` show it redacted, and `$raw` carries neither the field nor any other text
 * that contains it. Null when LIVCK cannot read the URL any more; regenerate it in the console.
 */
final readonly class DeclaredHeartbeat
{
    use RedactsSecrets;

    /**
     * @param string $serviceId the service the heartbeat is, for every service call (`services()->get()`)
     * @param string $key what the code calls the heartbeat
     * @param int|null $maxDurationSeconds longest run between start and end ping, null without a limit
     * @param ServiceStatus $status `unknown` until the first ping arrives, then `up` or `down`
     * @param HeartbeatDeclarationOutcome $result what this declaration did to the heartbeat
     * @param array<string, mixed> $raw the payload without `ping_url`
     */
    public function __construct(
        public string $serviceId,
        public string $key,
        public string $name,
        public HeartbeatKind $kind,
        public DeclaredSchedule $schedule,
        public int $graceSeconds,
        public int $scheduleTolerance,
        public ?int $maxDurationSeconds,
        public ?Secret $pingUrl,
        public ServiceStatus $status,
        public bool $paused,
        public HeartbeatDeclarationOutcome $result,
        public array $raw,
    ) {}

    /**
     * @param array<string, mixed> $data one entry of `heartbeats`
     */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        if (! array_key_exists('ping_url', $data)) {
            throw new UnexpectedResponseException('Field "ping_url": expected string or null, got nothing.');
        }

        $pingUrl = Field::nullableString($data, 'ping_url');

        if ($pingUrl === '') {
            throw new UnexpectedResponseException('A declared heartbeat came with an empty ping URL.');
        }

        unset($data['ping_url']);

        if ($pingUrl !== null) {
            $data = self::redacted($data, self::secretPart($pingUrl));
        }

        return new self(
            Field::string($data, 'service_id'),
            Field::string($data, 'key'),
            Field::string($data, 'name'),
            HeartbeatKind::fromApi(Field::string($data, 'kind')),
            DeclaredSchedule::fromArray(Field::object($data, 'schedule')),
            Field::int($data, 'grace_seconds'),
            Field::int($data, 'schedule_tolerance'),
            Field::nullableInt($data, 'max_duration_seconds'),
            $pingUrl === null ? null : new Secret($pingUrl),
            ServiceStatus::fromApi(Field::string($data, 'status')),
            Field::bool($data, 'paused'),
            HeartbeatDeclarationOutcome::fromApi(Field::string($data, 'result')),
            $data,
        );
    }

    public function wasCreated(): bool
    {
        return $this->result === HeartbeatDeclarationOutcome::Created;
    }

    public function wasUpdated(): bool
    {
        return $this->result === HeartbeatDeclarationOutcome::Updated;
    }

    /**
     * The part of the URL that makes it a credential: its last path segment (the token), or the
     * whole URL when that segment is too short to stand for it alone.
     */
    private static function secretPart(#[SensitiveParameter] string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $token = basename($path);

        return strlen($token) >= 16 ? $token : $url;
    }
}
