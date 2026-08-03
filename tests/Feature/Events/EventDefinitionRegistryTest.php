<?php

namespace Tests\Feature\Events;

use App\Modules\Events\Contracts\EventDefinitionContributor;
use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Enums\EventAttendanceStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Providers\EventsModuleServiceProvider;
use App\Modules\Events\Services\EventDefinitionRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class EventDefinitionRegistryTest extends TestCase
{
    public function test_provider_registers_configured_event_definitions(): void
    {
        $this->app->register(EventsModuleServiceProvider::class);

        $registry = app(EventDefinitionRegistry::class);

        $this->assertTrue($registry->has(
            EventDefinitionContribution::CATEGORY_EVENT_TYPE,
            'concert',
        ));
        $this->assertTrue($registry->has(
            EventDefinitionContribution::CATEGORY_STAKEHOLDER_ROLE,
            'production_manager',
        ));
        $this->assertTrue($registry->has(
            EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_TYPE,
            'livestream',
        ));
        $this->assertTrue($registry->has(
            EventDefinitionContribution::CATEGORY_ATTENDANCE_SOURCE,
            'operator',
        ));
    }

    public function test_contributors_extend_the_registry_without_events_importing_them(): void
    {
        $contributor = new class implements EventDefinitionContributor
        {
            public function definitions(): iterable
            {
                yield new EventDefinitionContribution(
                    category: EventDefinitionContribution::CATEGORY_EVENT_TYPE,
                    key: 'client_showcase',
                    label: 'Client Showcase',
                    sortOrder: 5,
                );
            }
        };

        $registry = new EventDefinitionRegistry(
            baseDefinitions: config('events.definitions', []),
            contributors: [$contributor],
        );

        $this->assertSame(
            'Client Showcase',
            $registry->get(
                EventDefinitionContribution::CATEGORY_EVENT_TYPE,
                'client_showcase',
            )?->label,
        );
        $this->assertSame(
            'client_showcase',
            array_key_first($registry->definitions(
                EventDefinitionContribution::CATEGORY_EVENT_TYPE,
            )),
        );
    }

    public function test_duplicate_category_keys_are_rejected(): void
    {
        $contributor = new class implements EventDefinitionContributor
        {
            public function definitions(): iterable
            {
                yield new EventDefinitionContribution(
                    category: EventDefinitionContribution::CATEGORY_EVENT_TYPE,
                    key: 'concert',
                    label: 'Duplicate Concert',
                );
            }
        };

        $registry = new EventDefinitionRegistry(
            baseDefinitions: config('events.definitions', []),
            contributors: [$contributor],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate Event definition [event_type:concert]');

        $registry->all();
    }

    public function test_universal_event_values_are_fixed_enums(): void
    {
        $this->assertEquals([
            'draft',
            'upcoming',
            'postponed',
            'completed',
            'cancelled',
        ], EventStatus::values());

        $this->assertEquals([
            'physical',
            'virtual',
            'hybrid',
        ], EventAttendanceMode::values());

        $this->assertEquals([
            'attended',
            'did_not_attend',
        ], EventAttendanceStatus::values());
    }
}