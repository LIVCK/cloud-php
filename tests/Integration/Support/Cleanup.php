<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Integration\Support;

use Closure;
use LIVCK\Cloud\Exceptions\NotFoundException;
use Throwable;

/**
 * Everything a run created, removed in reverse order once the run is over, whatever
 * happened in between. An object that is already gone counts as removed.
 */
final class Cleanup
{
    /** @var list<array{label: string, remove: Closure(): void}> */
    private array $entries = [];

    /**
     * @param Closure(): void $remove
     */
    public function add(string $label, Closure $remove): void
    {
        $this->entries[] = ['label' => $label, 'remove' => $remove];
    }

    /**
     * @return list<string> what could not be removed, with the reason
     */
    public function run(): array
    {
        $failures = [];

        foreach (array_reverse($this->entries) as $entry) {
            try {
                ($entry['remove'])();
            } catch (NotFoundException) {
                // Already gone: removed by a cascade or by hand.
            } catch (Throwable $e) {
                $failures[] = sprintf('%s: %s', $entry['label'], $e->getMessage());
            }
        }

        $this->entries = [];

        return $failures;
    }
}
