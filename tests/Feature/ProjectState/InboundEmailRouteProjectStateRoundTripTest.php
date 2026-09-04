<?php

namespace Tests\Feature\ProjectState;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundEmailRouteProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_email_routes_round_trip_by_stable_key(): void
    {
        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);

        InboundEmailRoute::query()->create([
            'key' => 'arive_application',
            'local_part' => 'arive+application',
            'label' => 'Arive application',
            'source' => 'arive',
            'context_key' => 'application',
            'is_active' => true,
            'contact_extraction_enabled' => true,
            'contact_extraction_definition' => [
                'version' => 1,
                'fields' => [
                    'email' => [
                        'source' => 'body_after_label',
                        'label' => 'Email',
                    ],
                ],
                'required_fields' => ['email'],
            ],
        ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(
            (int) config('project_state.version'),
            $document['version'],
        );
        $this->assertSame(
            (int) config('project_state.sections.inbound_messaging.version'),
            $document['sections']['inbound_messaging']['version'],
        );
        $this->assertCount(
            1,
            $document['sections']['inbound_messaging']['tables']['inbound_email_routes'],
        );

        DB::table('inbound_email_routes')->delete();

        $report = $projectState->validate($document);
        $this->assertTrue($report['valid'], implode(' ', $report['errors']));
        $this->assertTrue($projectState->import($document)['applied']);

        $restored = InboundEmailRoute::query()
            ->where('key', 'arive_application')
            ->sole();

        $this->assertSame('arive+application', $restored->local_part);
        $this->assertSame('arive', $restored->source);
        $this->assertSame('application', $restored->context_key);
        $this->assertTrue($restored->is_active);
        $this->assertTrue($restored->contact_extraction_enabled);
        $this->assertSame(
            'body_after_label',
            data_get(
                $restored->contact_extraction_definition,
                'fields.email.source',
            ),
        );
    }
}