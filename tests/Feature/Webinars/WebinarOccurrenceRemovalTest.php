<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WebinarOccurrenceRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_registration_occurrence_is_permanently_deleted_and_suppressed(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => 'zoom-remove-100',
            'meta' => [
                'provider' => [
                    'key' => 'zoom',
                    'data' => [
                        'zoom_uuid' => 'uuid-remove-100',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->delete(route('crm.webinars.destroy', $webinar))
            ->assertRedirect(route('crm.webinar-series.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('webinars', [
            'id' => $webinar->getKey(),
        ]);
        $this->assertDatabaseHas('webinar_occurrence_suppressions', [
            'webinar_series_id' => $series->getKey(),
            'platform' => 'zoom',
            'provider_event_type' => $webinar->providerEventTypeKey(),
            'external_id' => 'zoom-remove-100',
            'external_uuid' => 'uuid-remove-100',
            'reason' => 'operator_removed',
        ]);

        $suppression = WebinarOccurrenceSuppression::query()->firstOrFail();
        $this->assertNotNull($suppression->suppressed_at);
    }

    public function test_occurrence_with_registration_is_hidden_and_registration_reference_remains_resolvable(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create(['status' => 'active']);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => 'zoom-hide-200',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $contact = Contact::factory()->create();
        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
        ]);

        $this->actingAs($user)
            ->delete(route('crm.webinars.destroy', $webinar))
            ->assertRedirect(route('crm.webinar-series.index', ['archived' => 1]))
            ->assertSessionHas('success');

        $webinar->refresh();
        $registration->refresh();

        $this->assertNotNull($webinar->hidden_at);
        $this->assertSame('operator_removed', $webinar->hidden_reason);
        $this->assertTrue($registration->webinar?->is($webinar));
        $this->assertDatabaseCount('webinar_occurrence_suppressions', 0);
        $this->assertNull(
            app(ResolveRegisterableWebinarAction::class)->findForSeries(
                $series,
                $webinar->getKey(),
            ),
        );

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertViewHas('upcomingWebinars', fn (Collection $webinars): bool => ! $webinars->contains(
                fn (Webinar $candidate): bool => $candidate->is($webinar),
            ))
            ->assertViewHas('webinars', fn (Collection $webinars): bool => ! $webinars->contains(
                fn (Webinar $candidate): bool => $candidate->is($webinar),
            ));

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index', ['archived' => 1]))
            ->assertOk()
            ->assertViewHas('webinars', fn (Collection $webinars): bool => $webinars->contains(
                fn (Webinar $candidate): bool => $candidate->is($webinar),
            ));
    }

    public function test_replacement_link_prevents_hard_delete_even_without_registrations(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create();
        $source = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => 'zoom-source-300',
        ]);
        $replacement = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'replacement_of_webinar_id' => $source->getKey(),
            'external_id' => 'zoom-replacement-301',
        ]);

        $this->actingAs($user)
            ->delete(route('crm.webinars.destroy', $source))
            ->assertRedirect(route('crm.webinar-series.index', ['archived' => 1]));

        $this->assertDatabaseHas('webinars', [
            'id' => $source->getKey(),
            'hidden_reason' => 'operator_removed',
        ]);
        $this->assertDatabaseHas('webinars', [
            'id' => $replacement->getKey(),
            'replacement_of_webinar_id' => $source->getKey(),
        ]);
        $this->assertDatabaseCount('webinar_occurrence_suppressions', 0);
    }
}