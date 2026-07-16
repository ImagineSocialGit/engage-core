<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScheduledMessageFlowRoutesBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const FLOW_ROUTE_COLUMNS = [
        'flow_route_progress_id',
        'flow_route_plan_id',
        'flow_route_plan_item_id',
        'flow_route_progress_item_id',
        'flow_route_id',
        'flow_route_point_id',
        'flow_route_capability_id',
    ];

    public function test_scheduled_messages_do_not_own_flow_routes_foreign_keys(): void
    {
        foreach (self::FLOW_ROUTE_COLUMNS as $column) {
            $this->assertFalse(
                Schema::hasColumn('scheduled_messages', $column),
                "ScheduledMessage must not own FlowRoutes column [{$column}].",
            );
        }
    }

    public function test_messaging_source_does_not_import_flow_routes_models(): void
    {
        foreach (File::allFiles(app_path('Modules/Messaging')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $this->assertStringNotContainsString(
                'App\\Modules\\FlowRoutes\\',
                File::get($file->getPathname()),
                "Messaging file [{$file->getRelativePathname()}] imports FlowRoutes internals.",
            );
        }
    }

    public function test_route_origin_can_remain_generic_metadata_without_entering_horizon_identity(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create();
        $flowRouteMeta = [
            'flow_route_progress_id' => 101,
            'flow_route_plan_id' => 102,
            'flow_route_plan_item_id' => 103,
            'flow_route_progress_item_id' => 104,
            'flow_route_id' => 105,
            'flow_route_point_id' => 106,
            'flow_route_capability_id' => 107,
        ];

        $message = app(ScheduleMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'general',
            messageType: 'boundary_test',
            payloadClass: EmailPayload::class,
            payload: [
                'to' => $contact->email,
                'subject' => 'Boundary test',
                'body' => 'Boundary test.',
            ],
            meta: [
                'queue' => 'notifications',
                'source' => 'flow_routes',
                'flow_route' => $flowRouteMeta,
            ],
        );

        $this->assertSame($flowRouteMeta, data_get($message->meta, 'flow_route'));

        Queue::assertPushed(
            SendScheduledMessageJob::class,
            function (SendScheduledMessageJob $job): bool {
                foreach (self::FLOW_ROUTE_COLUMNS as $column) {
                    $this->assertArrayNotHasKey($column, $job->horizon);
                }

                return true;
            },
        );
    }
}
