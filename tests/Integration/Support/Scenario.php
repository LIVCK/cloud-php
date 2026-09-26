<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Integration\Support;

use Closure;
use LIVCK\Cloud\Builders\ServiceBuilder;
use LIVCK\Cloud\CloudClient;
use LIVCK\Cloud\Data\Incident;
use LIVCK\Cloud\Data\Maintenance;
use LIVCK\Cloud\Data\Service;
use LIVCK\Cloud\Data\Statuspage;
use LIVCK\Cloud\Data\StatuspageComponent;
use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Payloads\CreateStatuspage;
use RuntimeException;

/**
 * What the scenario suite shares: one run prefix for every name, slug, tag and hostname a
 * run creates (`sdke2e-3f9a1c2b`), bounded polling, a generated image, and the fixtures
 * every scenario starts from (a customer tag, a manual service, a status page), each
 * registered for removal the moment it exists.
 */
final class Scenario
{
    /** What every object of the scenario suite starts with; the safety-net sweep looks for it. */
    public const string PREFIX = 'sdke2e-';

    private static ?string $run = null;

    /** The run prefix, one per process: lowercase, a valid tag key, page slug and DNS label. */
    public static function run(): string
    {
        return self::$run ??= self::PREFIX . bin2hex(random_bytes(4));
    }

    /** A name of this run: `sdke2e-3f9a1c2b-<suffix>`. */
    public static function name(string $suffix): string
    {
        return self::run() . '-' . $suffix;
    }

    /** Whether a name, slug or key was made by the scenario suite (this run or an earlier one). */
    public static function isOurs(string $text): bool
    {
        return str_starts_with($text, self::PREFIX);
    }

    /**
     * Polls until the probe answers with something other than null, at most `$seconds`
     * long. Returns what the probe answered (null on timeout) and the seconds it took.
     *
     * @param Closure(): mixed $probe
     * @return array{0: mixed, 1: float}
     */
    public static function waitFor(int $seconds, Closure $probe, float $every = 2.0): array
    {
        $started = hrtime(true);

        while (true) {
            $result = $probe();
            $elapsed = (hrtime(true) - $started) / 1e9;

            if ($result !== null || $elapsed >= $seconds) {
                return [$result, $elapsed];
            }

            usleep((int) round($every * 1_000_000));
        }
    }

    /**
     * A small opaque PNG, built without an image library: a square in the LIVCK indigo.
     * Readable by every decoder (the server refuses raster images it cannot load).
     */
    public static function png(int $size = 16): string
    {
        $scanlines = '';

        for ($y = 0; $y < $size; $y++) {
            $scanlines .= "\0" . str_repeat("\x63\x66\xf1\xff", $size);
        }

        $compressed = gzcompress($scanlines, 9);

        if ($compressed === false) {
            throw new RuntimeException('zlib could not compress the image data.');
        }

        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 6, 0, 0, 0))
            . $chunk('IDAT', $compressed)
            . $chunk('IEND', '');
    }

    /** The tag of an end customer (`sdke2e-3f9a1c2b:customer-a`), found or created, removed at the end. */
    public static function customerTag(CloudClient $client, Cleanup $cleanup, string $customer, ?string $color = null): Tag
    {
        $ensured = DtoAudit::inspect($client->tags()->ensure(self::run(), 'customer-' . $customer, $color), 'tags.ensure');
        $tag = $ensured->tag;
        $cleanup->add('tag ' . $tag->label, static fn() => $client->tags()->delete($tag->id));

        return $tag;
    }

    /**
     * A service nothing probes, which makes it the cheapest fixture there is: no checks, no
     * check history, nothing that could open an incident. Removed with everything it alone
     * carried.
     */
    public static function manualService(CloudClient $client, Cleanup $cleanup, string $suffix, Tag ...$tags): Service
    {
        $builder = ServiceBuilder::manual(self::name($suffix));

        if ($tags !== []) {
            $builder = $builder->tags(...$tags);
        }

        return self::service($client, $cleanup, $builder);
    }

    /** Any service from a builder, registered for removal with everything it alone carried. */
    public static function service(CloudClient $client, Cleanup $cleanup, ServiceBuilder $builder): Service
    {
        $service = DtoAudit::inspect($client->services()->create($builder), 'services.create');
        $cleanup->add('service ' . $service->name, static fn() => $client->services()->delete($service->id, deleteOrphanedIncidents: true, deleteOrphanedMaintenances: true));

        return $service;
    }

    /** A status page with a slug of this run (`sdke2e-3f9a1c2b-<suffix>`), removed at the end. */
    public static function statuspage(CloudClient $client, Cleanup $cleanup, string $suffix): Statuspage
    {
        $page = DtoAudit::inspect(
            $client->statuspages()->create(CreateStatuspage::make('Status ' . self::name($suffix))->withSlug(self::name($suffix))),
            'statuspages.create',
        );
        $cleanup->add('status page ' . $page->slug, static fn() => $client->statuspages()->delete($page->id));

        return $page;
    }

    /**
     * A create through the escape hatch (`$client->request('POST', …)`) for what the SDK
     * does not write yet (incidents, maintenance windows); answers the `data` object of
     * the 201.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function post(CloudClient $client, string $path, array $json): array
    {
        $response = $client->request('POST', $path, json: $json);

        if ($response->status() !== 201) {
            throw new RuntimeException(sprintf('POST %s answered %d, not 201.', $path, $response->status()));
        }

        return LiveApi::data($response);
    }

    /**
     * The ids of a page's items, or of a list of DTOs, in order.
     *
     * @template T of Service|Incident|Maintenance|Tag|Statuspage|StatuspageComponent
     *
     * @param Page<T>|list<T> $items
     * @return list<string>
     */
    public static function ids(Page|array $items): array
    {
        $ids = [];

        foreach ($items instanceof Page ? $items->items : $items as $item) {
            $ids[] = $item->id;
        }

        return $ids;
    }

    /** Prints a journey's log and the audit's one-line summary, whatever happened. */
    public static function report(Journey $journey): void
    {
        $journey->note(DtoAudit::summary());
        $journey->note(sprintf('transport: %s; %s', LiveApi::attempts()->summary(), LiveApi::sleeper()->summary()));

        fwrite(STDERR, "\n" . $journey->report() . "\n");
    }
}
