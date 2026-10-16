<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarRegistrationResponse;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarInformationArchitectureTest extends TestCase
{
    use RefreshDatabase;


    public function test_removed_sessions_can_be_restored_from_the_webinar_type_surface(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'title' => 'Homebuyer Game Plan',
        ]);

        $hidden = Webinar::factory()->for($series, 'webinarSeries')->create([
            'hidden_at' => now(),
            'hidden_reason' => Webinar::HIDDEN_REASON_OPERATOR_REMOVED,
        ]);

        $suppression = WebinarOccurrenceSuppression::query()->create([
            'webinar_series_id' => $series->getKey(),
            'platform' => 'zoom',
            'provider_event_type' => $series->providerEventTypeKey(),
            'external_id' => 'removed-zoom-456',
            'reason' => WebinarOccurrenceSuppression::REASON_OPERATOR_REMOVED,
            'suppressed_at' => now(),
            'meta' => [
                'source_title' => 'Removed Zoom Session',
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('crm.webinars.restore', $hidden))
            ->assertRedirect(route('crm.webinar-series.show', $series))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('webinars', [
            'id' => $hidden->getKey(),
            'hidden_at' => null,
            'hidden_reason' => null,
        ]);

        $this->actingAs($user)
            ->patch(route(
                'crm.webinar-occurrence-suppressions.restore',
                $suppression,
            ))
            ->assertRedirect(route('crm.webinar-series.show', $series))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('webinar_occurrence_suppressions', [
            'id' => $suppression->getKey(),
        ]);
    }
}