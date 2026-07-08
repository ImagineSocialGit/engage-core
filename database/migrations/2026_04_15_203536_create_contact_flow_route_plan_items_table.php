<?php

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
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
        Schema::create('contact_flow_route_plan_items', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(ContactFlowRouteProgress::class, 'contact_flow_route_progress_id')
                ->constrained(
                    table: 'contact_flow_route_progress',
                    indexName: 'cfr_plan_items_progress_fk',
                )
                ->cascadeOnDelete();

            $table->foreignIdFor(ContactFlowRoutePlan::class, 'contact_flow_route_plan_id')
                ->constrained(
                    table: 'contact_flow_route_plans',
                    indexName: 'cfr_plan_items_plan_fk',
                )
                ->cascadeOnDelete();

            $table->foreignIdFor(FlowRoute::class)
                ->constrained(indexName: 'cfr_plan_items_route_fk')
                ->cascadeOnDelete();

            $table->foreignIdFor(FlowRoutePoint::class)
                ->nullable()
                ->constrained(
                    table: 'flow_route_points',
                    indexName: 'cfr_plan_items_route_point_fk',
                )
                ->nullOnDelete();

            $table->foreignIdFor(Point::class)
                ->nullable()
                ->constrained(indexName: 'cfr_plan_items_point_fk')
                ->nullOnDelete();

            $table->foreignIdFor(FlowRouteCapability::class)
                ->nullable()
                ->constrained(
                    table: 'flow_route_capabilities',
                    indexName: 'cfr_plan_items_capability_fk',
                )
                ->nullOnDelete();

            $table->string('key')->nullable();
            $table->string('point_type', 80)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('sequence')->default(0);
            $table->unsignedInteger('attempt')->default(1);

            $table->string('source')->default('template')->index();
            $table->string('status')->default('pending')->index();
            $table->string('result_reason')->nullable();

            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamp('resume_at')->nullable()->index();
            $table->string('waiting_event_key')->nullable()->index();

            $table->json('definition_snapshot')->nullable();
            $table->json('settings_snapshot')->nullable();
            $table->json('cancel_conditions_snapshot')->nullable();
            $table->json('correlation')->nullable();
            $table->json('result_payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['contact_flow_route_progress_id', 'status'], 'cfrpi_progress_status_idx');
            $table->index(['contact_flow_route_plan_id', 'status', 'sort_order'], 'cfrpi_plan_status_order_idx');
            $table->index(['contact_flow_route_plan_id', 'sort_order', 'sequence'], 'cfrpi_plan_order_sequence_idx');
            $table->index(['flow_route_id', 'status'], 'cfrpi_route_status_idx');
            $table->index(['flow_route_point_id', 'status'], 'cfrpi_route_point_status_idx');
            $table->index(['flow_route_capability_id', 'status'], 'cfrpi_capability_status_idx');
            $table->index(['status', 'resume_at'], 'cfrpi_status_resume_idx');
            $table->index(['waiting_event_key', 'status'], 'cfrpi_waiting_event_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_flow_route_plan_items');
    }
};
