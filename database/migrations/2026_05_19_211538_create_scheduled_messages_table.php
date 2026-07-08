<?php

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();

            $table->morphs('recipient');
            $table->nullableMorphs('context');

            $table->foreignIdFor(ContactFlowRouteProgress::class, 'flow_route_progress_id')
                ->nullable()
                ->constrained('contact_flow_route_progress')
                ->nullOnDelete();

            $table->foreignIdFor(ContactFlowRoutePlan::class, 'flow_route_plan_id')
                ->nullable()
                ->constrained('contact_flow_route_plans')
                ->nullOnDelete();

            $table->foreignIdFor(ContactFlowRoutePlanItem::class, 'flow_route_plan_item_id')
                ->nullable()
                ->constrained('contact_flow_route_plan_items')
                ->nullOnDelete();

            $table->foreignIdFor(ContactFlowRouteProgressItem::class, 'flow_route_progress_item_id')
                ->nullable()
                ->constrained('contact_flow_route_progress_items')
                ->nullOnDelete();

            $table->foreignIdFor(FlowRoute::class)
                ->nullable()
                ->constrained('flow_routes')
                ->nullOnDelete();

            $table->foreignIdFor(FlowRoutePoint::class)
                ->nullable()
                ->constrained('flow_route_points')
                ->nullOnDelete();

            $table->foreignIdFor(FlowRouteCapability::class)
                ->nullable()
                ->constrained('flow_route_capabilities')
                ->nullOnDelete();

            $table->string('channel')->index();
            $table->string('message_type')->index();

            $table->string('purpose')->index();
            $table->string('scope')->index();

            $table->string('payload_class');
            $table->string('queue')->nullable()->index();

            $table->json('dispatch_keys')->nullable();
            $table->string('definition_config_path')->nullable()->index();

            $table->json('payload');

            $table->timestamp('send_at')->index();

            $table->string('status')
                ->default('pending')
                ->index();

            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('skipped_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();

            $table->string('dedupe_key')
                ->nullable()
                ->unique();

            $table->text('failure_reason')->nullable();
            $table->text('skip_reason')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index([
                'channel',
                'purpose',
                'scope',
            ], 'scheduled_messages_channel_purpose_scope_index');

            $table->index([
                'context_type',
                'context_id',
                'channel',
                'message_type',
            ], 'scheduled_messages_context_channel_type_index');

            $table->index([
                'status',
                'send_at',
            ], 'scheduled_messages_status_send_at_index');

            $table->index([
                'queue',
                'status',
                'send_at',
            ], 'scheduled_messages_queue_status_send_at_index');

            $table->index(['flow_route_progress_id', 'status'], 'sm_route_progress_status_idx');
            $table->index(['flow_route_plan_item_id', 'status'], 'sm_route_plan_item_status_idx');
            $table->index(['flow_route_progress_item_id', 'status'], 'sm_route_progress_item_status_idx');
            $table->index(['flow_route_id', 'flow_route_point_id', 'status'], 'sm_route_point_status_idx');
            $table->index(['flow_route_capability_id', 'status'], 'sm_route_capability_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
