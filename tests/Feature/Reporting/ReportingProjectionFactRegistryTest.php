<?php

namespace Tests\Feature\Reporting;

use App\Support\Reporting\Contracts\ReportingProjectionFactContributor;
use App\Support\Reporting\Data\ReportingProjectionFact;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use App\Support\Reporting\ReportingProjectionFactRegistry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class ReportingProjectionFactRegistryTest extends TestCase
{
    public function test_registry_collects_bounded_projection_facts_from_neutral_contributors(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-08-16 12:00:00', 'UTC');
        $contributor = new class($occurredAt) implements ReportingProjectionFactContributor
        {
            public function __construct(
                private readonly CarbonImmutable $occurredAt,
            ) {}

            public function key(): string
            {
                return 'example';
            }

            public function facts(ReportingProjectionWindow $window): iterable
            {
                yield new ReportingProjectionFact(
                    key: 'example.completed',
                    version: 1,
                    occurredAt: $this->occurredAt,
                    subjectType: 'example',
                    subjectId: '42',
                    correlationId: 'correlation-42',
                    dimensions: ['surface' => 'public'],
                    values: ['completed' => true],
                );
            }
        };

        $registry = new ReportingProjectionFactRegistry([$contributor]);
        $facts = iterator_to_array($registry->facts(
            new ReportingProjectionWindow(
                startsAt: $occurredAt->startOfDay(),
                endsAt: $occurredAt->endOfDay(),
            ),
        ), false);

        $this->assertCount(1, $facts);
        $this->assertSame('example.completed', $facts[0]->key);
        $this->assertSame('correlation-42', $facts[0]->correlationId);
        $this->assertEquals(['surface' => 'public'], $facts[0]->dimensions);
        $this->assertEquals(['completed' => true], $facts[0]->values);
    }

    public function test_registry_rejects_facts_outside_the_requested_window(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-08-17 00:00:01', 'UTC');
        $contributor = new class($occurredAt) implements ReportingProjectionFactContributor
        {
            public function __construct(
                private readonly CarbonImmutable $occurredAt,
            ) {}

            public function key(): string
            {
                return 'outside_window';
            }

            public function facts(ReportingProjectionWindow $window): iterable
            {
                yield new ReportingProjectionFact(
                    key: 'example.outside_window',
                    version: 1,
                    occurredAt: $this->occurredAt,
                    subjectType: 'example',
                    subjectId: '42',
                );
            }
        };
        $registry = new ReportingProjectionFactRegistry([$contributor]);

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($registry->facts(
            new ReportingProjectionWindow(
                startsAt: CarbonImmutable::parse('2026-08-16 00:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-08-16 23:59:59', 'UTC'),
            ),
        ), false);
    }

    public function test_registry_rejects_duplicate_contributor_keys(): void
    {
        $contributor = new class implements ReportingProjectionFactContributor
        {
            public function key(): string
            {
                return 'duplicate';
            }

            public function facts(ReportingProjectionWindow $window): iterable
            {
                return [];
            }
        };

        $this->expectException(InvalidArgumentException::class);

        new ReportingProjectionFactRegistry([
            $contributor,
            $contributor,
        ]);
    }
}