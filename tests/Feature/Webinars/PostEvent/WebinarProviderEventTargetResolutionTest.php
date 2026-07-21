<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarProviderEventTargetAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarProviderEventTargetResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_event_type_resolves_the_correct_colliding_occurrence(): void
    {
        $webinar = $this->makeOccurrence(
            eventType: WebinarProviderEventType::Webinar,
            externalId: '123456789',
            uuid: 'webinar-uuid',
        );

        $meeting = $this->makeOccurrence(
            eventType: WebinarProviderEventType::Meeting,
            externalId: '123456789',
            uuid: 'meeting-uuid',
        );

        $resolver = app(ResolveWebinarProviderEventTargetAction::class);

        $this->assertTrue($resolver->execute(
            provider: 'zoom',
            externalWebinarId: '123456789',
            providerEventType: WebinarProviderEventType::Webinar->value,
        )?->is($webinar));

        $this->assertTrue($resolver->execute(
            provider: 'zoom',
            externalWebinarId: '123456789',
            providerEventType: WebinarProviderEventType::Meeting->value,
        )?->is($meeting));
    }

    public function test_uuid_disambiguates_an_untyped_generic_provider_event(): void
    {
        $this->makeOccurrence(
            eventType: WebinarProviderEventType::Webinar,
            externalId: '123456789',
            uuid: 'webinar-uuid',
        );

        $meeting = $this->makeOccurrence(
            eventType: WebinarProviderEventType::Meeting,
            externalId: '123456789',
            uuid: 'meeting-uuid',
        );

        $resolved = app(ResolveWebinarProviderEventTargetAction::class)->execute(
            provider: 'zoom',
            externalWebinarId: '123456789',
            externalWebinarUuid: 'meeting-uuid',
        );

        $this->assertTrue($resolved?->is($meeting));
    }

    public function test_untyped_legacy_events_resolve_only_when_one_candidate_exists(): void
    {
        $onlyCandidate = $this->makeOccurrence(
            eventType: WebinarProviderEventType::Webinar,
            externalId: '111222333',
            uuid: null,
        );

        $resolver = app(ResolveWebinarProviderEventTargetAction::class);

        $this->assertTrue($resolver->execute(
            provider: 'zoom',
            externalWebinarId: '111222333',
        )?->is($onlyCandidate));

        $this->makeOccurrence(
            eventType: WebinarProviderEventType::Webinar,
            externalId: '444555666',
            uuid: 'webinar-collision-uuid',
        );

        $this->makeOccurrence(
            eventType: WebinarProviderEventType::Meeting,
            externalId: '444555666',
            uuid: 'meeting-collision-uuid',
        );

        $this->assertNull($resolver->execute(
            provider: 'zoom',
            externalWebinarId: '444555666',
        ));
    }

    public function test_missing_invalid_and_duplicate_uuid_targets_are_rejected(): void
    {
        $this->makeOccurrence(
            eventType: WebinarProviderEventType::Webinar,
            externalId: '123456789',
            uuid: 'duplicate-uuid',
        );

        $this->makeOccurrence(
            eventType: WebinarProviderEventType::Meeting,
            externalId: '123456789',
            uuid: 'duplicate-uuid',
        );

        $resolver = app(ResolveWebinarProviderEventTargetAction::class);

        $this->assertNull($resolver->execute(
            provider: 'zoom',
            externalWebinarId: '123456789',
            externalWebinarUuid: 'duplicate-uuid',
        ));

        $this->assertNull($resolver->execute(
            provider: 'zoom',
            externalWebinarId: '123456789',
            providerEventType: 'unsupported',
        ));

        $this->assertNull($resolver->execute(
            provider: 'zoom',
            externalWebinarId: 'missing',
            providerEventType: WebinarProviderEventType::Webinar->value,
        ));
    }

    private function makeOccurrence(
        WebinarProviderEventType $eventType,
        string $externalId,
        ?string $uuid,
    ): Webinar {
        return Webinar::factory()->create([
            'platform' => 'zoom',
            'provider_event_type' => $eventType->value,
            'external_id' => $externalId,
            'meta' => $uuid === null
                ? []
                : [
                    'provider' => [
                        'key' => 'zoom',
                        'data' => [
                            'zoom_uuid' => $uuid,
                        ],
                    ],
                ],
        ]);
    }
}