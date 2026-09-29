<?php

namespace Tests\Feature\Events;

use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendance;
use App\Modules\Events\Models\EventExternalReference;
use App\Modules\Events\Models\EventStakeholder;
use App\Modules\Events\Providers\EventsModuleServiceProvider;
use App\Modules\Events\Readiness\CoreEventReadinessContributor;
use App\Modules\Events\Services\EventDefinitionRegistry;
use App\Modules\Events\Services\EventReadinessRegistry;
use App\Modules\Events\Validation\EventsSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventsSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_events_setup_has_no_events_findings(): void
    {
        $this->assertSame([], $this->codes());
    }

    public function test_invalid_definition_registry_is_reported_without_throwing(): void
    {
        $definitions = new EventDefinitionRegistry(
            baseDefinitions: [
                EventDefinitionContribution::CATEGORY_EVENT_TYPE => [
                    'broken' => ['label' => ''],
                ],
            ],
        );

        $this->assertContains(
            'events.definition_registry_invalid',
            $this->codes($definitions),
        );
    }

    public function test_missing_core_readiness_capability_is_reported(): void
    {
        $this->assertContains(
            'events.core_readiness_missing',
            $this->codes(
                readiness: new EventReadinessRegistry(),
            ),
        );
    }

    public function test_upcoming_event_that_no_longer_passes_core_readiness_is_reported(): void
    {
        $event = Event::factory()->upcoming()->create();

        DB::table('events')
            ->where('id', $event->getKey())
            ->update(['title' => '']);

        $finding = collect($this->findings())
            ->firstWhere('code', 'events.upcoming_core_readiness_failed');

        $this->assertNotNull($finding);
        $this->assertSame((int) $event->getKey(), $finding['context']['event_id']);
        $this->assertContains(
            'title_missing',
            $finding['context']['readiness_codes'],
        );
    }

    public function test_persisted_registry_backed_keys_are_validated_against_active_definitions(): void
    {
        $event = Event::factory()->create();
        $reference = EventExternalReference::factory()->forEvent($event)->create();
        $stakeholder = EventStakeholder::factory()->forEvent($event)->create();
        $attendance = EventAttendance::factory()->forEvent($event)->create();

        DB::table('events')
            ->where('id', $event->getKey())
            ->update(['type_key' => 'missing_type']);

        DB::table('event_external_references')
            ->where('id', $reference->getKey())
            ->update([
                'provider_key' => 'missing_provider',
                'reference_type' => 'missing_reference_type',
            ]);

        DB::table('event_stakeholders')
            ->where('id', $stakeholder->getKey())
            ->update(['role_key' => 'missing_role']);

        DB::table('event_attendances')
            ->where('id', $attendance->getKey())
            ->update(['source_key' => 'missing_source']);

        $codes = $this->codes();

        foreach ([
            'events.event_type_unregistered',
            'events.external_reference_provider_unregistered',
            'events.external_reference_type_unregistered',
            'events.stakeholder_role_unregistered',
            'events.attendance_source_unregistered',
        ] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_external_reference_identity_and_primary_event_ownership_are_validated(): void
    {
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();
        $otherReference = EventExternalReference::factory()
            ->forEvent($otherEvent)
            ->create();
        $emptyReference = EventExternalReference::factory()
            ->forEvent($event)
            ->create();

        DB::table('event_external_references')
            ->where('id', $emptyReference->getKey())
            ->update([
                'external_id' => null,
                'url' => null,
            ]);

        DB::table('events')
            ->where('id', $event->getKey())
            ->update([
                'primary_external_reference_id' => $otherReference->getKey(),
            ]);

        $codes = $this->codes();

        $this->assertContains(
            'events.external_reference_identity_missing',
            $codes,
        );
        $this->assertContains(
            'events.primary_external_reference_event_mismatch',
            $codes,
        );
    }

    public function test_events_provider_registers_setup_validation_contributor(): void
    {
        $this->app->register(
            EventsModuleServiceProvider::class,
            force: true,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            iterator_to_array(
                $this->app->tagged('setup.validation_contributors'),
                false,
            ),
        );

        $this->assertContains(
            EventsSetupValidationContributor::class,
            $classes,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(
        ?EventDefinitionRegistry $definitions = null,
        ?EventReadinessRegistry $readiness = null,
    ): array {
        $definitions ??= new EventDefinitionRegistry(
            baseDefinitions: config('events.definitions', []),
        );
        $readiness ??= new EventReadinessRegistry([
            new CoreEventReadinessContributor(),
        ]);

        return array_map(
            static fn ($finding): array => $finding->toArray(),
            iterator_to_array(
                (new EventsSetupValidationContributor(
                    definitions: $definitions,
                    readiness: $readiness,
                ))->findings(),
                false,
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    private function codes(
        ?EventDefinitionRegistry $definitions = null,
        ?EventReadinessRegistry $readiness = null,
    ): array {
        return array_column(
            $this->findings($definitions, $readiness),
            'code',
        );
    }
}