<?php

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_enrollments', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Campaign::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

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

            $table->string('campaign_key', 120)->index();

            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 120)->index();

            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('current_step')->nullable();

            $table->foreignIdFor(CampaignStep::class, 'current_campaign_step_id')
                ->nullable()
                ->constrained('campaign_steps')
                ->nullOnDelete();

            $table->json('start_context')->nullable();
            $table->json('exit_conditions')->nullable();

            $table->timestamp('exited_at')->nullable()->index();
            $table->string('exit_reason')->nullable()->index();

            $table->foreignIdFor(ScheduledMessage::class, 'last_scheduled_message_id')
                ->nullable()
                ->constrained('scheduled_messages')
                ->nullOnDelete();

            $table->string('dedupe_key', 191)->nullable()->unique();

            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('paused_at')->nullable()->index();
            $table->timestamp('resumed_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['campaign_id', 'status']);
            $table->index(['contact_id', 'campaign_id', 'status']);

            $table->index([
                'contact_id',
                'campaign_key',
                'channel',
                'status',
            ], 'campaign_enrollments_contact_campaign_channel_status_index');

            $table->index([
                'campaign_key',
                'channel',
                'purpose',
                'scope',
                'status',
            ], 'campaign_enrollments_campaign_message_status_index');

            $table->index([
                'source_id',
                'campaign_key',
                'channel',
            ], 'campaign_enrollments_source_campaign_channel_index');

            $table->index(['flow_route_progress_id', 'status'], 'ce_route_progress_status_idx');
            $table->index(['flow_route_plan_item_id', 'status'], 'ce_route_plan_item_status_idx');
            $table->index(['flow_route_progress_item_id', 'status'], 'ce_route_progress_item_status_idx');
            $table->index(['flow_route_id', 'flow_route_point_id', 'status'], 'ce_route_point_status_idx');
            $table->index(['flow_route_capability_id', 'status'], 'ce_route_capability_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_enrollments');
    }
};
