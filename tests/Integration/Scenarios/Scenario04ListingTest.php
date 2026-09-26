<?php

declare(strict_types=1);

use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Pagination\Page;
use LIVCK\Cloud\Query\ServiceQuery;
use LIVCK\Cloud\Tests\Integration\Support\Cleanup;
use LIVCK\Cloud\Tests\Integration\Support\DtoAudit;
use LIVCK\Cloud\Tests\Integration\Support\Journey;
use LIVCK\Cloud\Tests\Integration\Support\LiveApi;
use LIVCK\Cloud\Tests\Integration\Support\Scenario;

/**
 * Scenario 4, listing: the tag filter returns exactly one customer's services, pages of
 * five add up, each() walks every page, an unknown label is empty and a blank one is refused.
 * Manual services keep it cheap: nothing probes them.
 */
$skip = LiveApi::skipReason();

describe('scenario 4: listing', function () use ($skip): void {
    it('filters by tag, pages and walks the list', function (): void {
        $client = LiveApi::client();
        $journey = new Journey();
        $cleanup = new Cleanup();
        $cleanupFailures = [];

        try {
            /** @var array<string, list<string>> $byCustomer */
            $byCustomer = $journey->step('1 three customers with 6, 4 and 3 manual services (one shared)', function () use ($client, $cleanup): array {
                $a = Scenario::customerTag($client, $cleanup, 'a');
                $b = Scenario::customerTag($client, $cleanup, 'b');
                $c = Scenario::customerTag($client, $cleanup, 'c');
                $ids = ['a' => [], 'b' => [], 'c' => []];

                for ($i = 1; $i <= 5; $i++) {
                    $ids['a'][] = Scenario::manualService($client, $cleanup, 'list-a' . $i, $a)->id;
                }

                for ($i = 1; $i <= 3; $i++) {
                    $ids['b'][] = Scenario::manualService($client, $cleanup, 'list-b' . $i, $b)->id;
                }

                $shared = Scenario::manualService($client, $cleanup, 'list-ab', $a, $b);
                $ids['a'][] = $shared->id;
                $ids['b'][] = $shared->id;

                for ($i = 1; $i <= 3; $i++) {
                    $ids['c'][] = Scenario::manualService($client, $cleanup, 'list-c' . $i, $c)->id;
                }

                expect($shared->tagIds())->toEqualCanonicalizing([$a->id, $b->id])
                    ->and($shared->hasTag($a->label))->toBeTrue()
                    ->and($shared->hasTag($c->label))->toBeFalse();

                return $ids;
            });

            $journey->step('2 withTag() returns exactly each customer\'s services, by label and by Tag', function () use ($client, $byCustomer): void {
                foreach ($byCustomer as $customer => $wanted) {
                    $label = Scenario::run() . ':customer-' . $customer;
                    $tag = $client->tags()->findByLabel($label);
                    $byLabel = DtoAudit::inspect($client->services()->list(ServiceQuery::make()->withTag($label)->withPerPage(100)), 'services.list (label)');
                    $byTag = $tag instanceof Tag ? $client->services()->list(ServiceQuery::make()->withTag($tag)->withPerPage(100)) : null;

                    expect($tag)->not->toBeNull()
                        ->and(Scenario::ids($byLabel))->toEqualCanonicalizing($wanted, 'customer ' . $customer)
                        ->and($byLabel->total)->toBe(count($wanted))
                        ->and($byTag instanceof Page ? Scenario::ids($byTag) : [])->toEqualCanonicalizing($wanted, 'customer ' . $customer . ' by Tag');

                    foreach ($byLabel->items as $service) {
                        expect($service->hasTag($label))->toBeTrue();
                    }
                }
            });

            $journey->step('3 perPage(5): the pages add up and each() counts the same', function () use ($client, $byCustomer, $journey): void {
                $label = Scenario::run() . ':customer-a';
                $first = $client->services()->list(ServiceQuery::make()->withTag($label)->withPerPage(5));
                $second = $first->nextPage();

                expect($first->total)->toBe(6)
                    ->and($first->perPage)->toBe(5)
                    ->and($first->lastPage)->toBe(2)
                    ->and(count($first))->toBe(5)
                    ->and($first->from)->toBe(1)
                    ->and($first->to)->toBe(5)
                    ->and($first->hasMorePages())->toBeTrue()
                    ->and($second instanceof Page ? count($second) : 0)->toBe(1)
                    ->and($second?->from)->toBe(6)
                    ->and($second?->to)->toBe(6)
                    ->and($second?->hasMorePages())->toBeFalse();

                $walked = [];

                foreach ($client->services()->each(ServiceQuery::make()->withTag($label)->withPerPage(5)) as $service) {
                    $walked[] = DtoAudit::inspect($service, 'services.each')->id;
                }

                expect($walked)->toEqualCanonicalizing($byCustomer['a']);

                // Across the whole organization: however many services exist, the pages of five add up to the total.
                $all = $client->services()->list(ServiceQuery::make()->withPerPage(5));
                $seen = [];
                $pages = 0;

                foreach ($client->services()->each(ServiceQuery::make()->withPerPage(5)) as $service) {
                    $seen[$service->id] = true;
                }

                for ($page = $all; $page instanceof Page; $page = $page->nextPage()) {
                    $pages++;
                }

                $ours = array_unique(array_merge(...array_values($byCustomer)));

                expect($all->lastPage)->toBe((int) ceil($all->total / 5))
                    ->and($pages)->toBe($all->lastPage)
                    ->and(count($seen))->toBe($all->total)
                    ->and(array_diff($ours, array_keys($seen)))->toBe([]);

                $journey->note(sprintf('organization-wide: %d services on %d pages of 5', $all->total, $all->lastPage));
            });

            $journey->step('4 an unknown label is an empty page, a blank one is refused before sending', function () use ($client): void {
                $unknown = $client->services()->list(ServiceQuery::make()->withTag(Scenario::run() . ':customer-zz'));
                $unknownKey = $client->services()->list(ServiceQuery::make()->withTag('sdke2e-nobody:x'));
                $sent = LiveApi::attempts()->attempts();

                expect($unknown->total)->toBe(0)
                    ->and($unknown->isEmpty())->toBeTrue()
                    ->and($unknown->first())->toBeNull()
                    ->and($unknown->lastPage)->toBe(1)
                    ->and($unknownKey->total)->toBe(0);

                expect(static fn(): ServiceQuery => ServiceQuery::make()->withTag(''))->toThrow(InvalidArgumentException::class);
                expect(static fn(): ServiceQuery => ServiceQuery::make()->withTag('  '))->toThrow(InvalidArgumentException::class);
                expect(static fn(): ServiceQuery => ServiceQuery::make()->withPerPage(0))->toThrow(InvalidArgumentException::class);
                expect(static fn(): ServiceQuery => ServiceQuery::make()->withPerPage(101))->toThrow(InvalidArgumentException::class);
                expect(static fn(): ServiceQuery => ServiceQuery::make()->withPage(0))->toThrow(InvalidArgumentException::class);
                expect(LiveApi::attempts()->attempts())->toBe($sent);
            });
        } finally {
            $cleanupFailures = $journey->step('5 cleanup: services, then tags', static fn(): array => $cleanup->run());
            Scenario::report($journey);
        }

        expect($cleanupFailures)->toBe([], 'Cleanup left objects behind: ' . implode('; ', $cleanupFailures));
    })->skip($skip !== null, $skip ?? '');
});
