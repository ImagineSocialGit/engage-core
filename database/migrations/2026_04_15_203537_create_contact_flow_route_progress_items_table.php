<?php

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\Point;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_flow_route_progress_items', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(ContactFlowRouteProgress::class, 'contact_flow_route_progress_id')
                ->constrained(
                    table: 'contact_flow_route_progress',
                    indexName: 'cfr_progress_items_progress_fk',
                )
                ->cascadeOnDelete();

            $table->foreignIdFor(ContactFlowRoutePlan::class, 'contact_flow_route_plan_id')
                ->nullable()
                ->constrained(
                    table: 'contact_flow_route_plans',
                    indexName: 'cfr_progress_items_plan_fk',
                )
                ->nullOnDelete();

            $table->foreignIdFor(ContactFlowRoutePlanItem::class, 'contact_flow_route_plan_item_id')
                ->nullable()
                ->constrained(
                    table: 'contact_flow_route_plan_items',
                    indexName: 'cfr_progress_items_plan_item_fk',
                )
                ->nullOnDelete();

            $table->foreignIdFor(FlowRoute::class)
                ->constrained(indexName: 'cfr_progress_items_route_fk')
                ->cascadeOnDelete();

            $table->foreignIdFor(FlowRoutePoint::class)
                ->nullable()
                ->constrained(
                    table: 'flow_route_points',
                    indexName: 'cfr_progress_items_route_point_fk',
                )
                ->nullOnDelete();

            $table->foreignIdFor(Point::class)
                ->nullable()
                ->constrained(indexName: 'cfr_progress_items_point_fk')
                ->nullOnDelete();

            $table->foreignIdFor(FlowRouteCapability::class)
                ->nullable()
                ->constrained(
                    table: 'flow_route_capabilities',
                    indexName: 'cfr_progress_items_capability_fk',
                )
                ->nullOnDelete();

            $table->nullableMorphs('created_subject', 'cfrxi_created_subject_morph_idx');

            $table->string('key')->nullable();
            $table->string('point_type', 80)->index();
            $table->unsignedInteger('sequence')->default(0);
            $table->unsignedInteger('attempt')->default(1);

            $table->string('status')->default('started')->index();
            $table->string('result_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamp('resume_at')->nullable()->index();
            $table->string('waiting_event_key')->nullable()->index();

            $table->string('correlation_key')->nullable()->index();
            $table->string('correlation_type')->nullable()->index();
            $table->json('correlation')->nullable();
            $table->json('result_payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['contact_flow_route_progress_id', 'status'], 'cfrxi_progress_status_idx');
            $table->index(['contact_flow_route_plan_id', 'status'], 'cfrxi_plan_status_idx');
            $table->index(['contact_flow_route_plan_item_id', 'status'], 'cfrxi_plan_item_status_idx');
            $table->index(['flow_route_id', 'status'], 'cfrxi_route_status_idx');
            $table->index(['flow_route_point_id', 'status'], 'cfrxi_route_point_status_idx');
            $table->index(['flow_route_capability_id', 'status'], 'cfrxi_capability_status_idx');
            $table->index(['status', 'resume_at'], 'cfrxi_status_resume_idx');
            $table->index(['waiting_event_key', 'status'], 'cfrxi_waiting_event_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_flow_route_progress_items');
    }
};
