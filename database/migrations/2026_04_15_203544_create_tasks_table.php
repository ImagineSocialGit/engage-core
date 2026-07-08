<?php

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('related');

            $table->nullableMorphs('assigned_to');

            $table->string('responsible_party')->default('internal')->index();
            $table->nullableMorphs('responsible');

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

            // task_templates is created immediately after tasks in the current pre-prod migration order.
            // Keep the structured column now, but do not add a DB constraint here.
            $table->foreignIdFor(TaskTemplate::class)
                ->nullable()
                ->index();

            $table->string('task_template_key')->nullable()->index();

            $table->string('source')->default('manual')->index();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status')->default('open')->index();
            $table->string('priority')->nullable()->index();

            $table->timestamp('due_at')->nullable()->index();

            $table->timestamp('completed_at')->nullable()->index();

            $table->timestamp('canceled_at')->nullable()->index();
            $table->string('canceled_reason')->nullable();

            $table->timestamp('archived_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['assigned_to_type', 'assigned_to_id', 'status'], 'tasks_assigned_to_status_index');
            $table->index(['responsible_party', 'status', 'archived_at'], 'tasks_responsible_party_status_index');
            $table->index(['related_type', 'related_id', 'status', 'archived_at'], 'tasks_related_status_archived_index');
            $table->index(['status', 'archived_at', 'due_at'], 'tasks_status_archived_due_index');
            $table->index(['flow_route_progress_id', 'status'], 'tasks_route_progress_status_idx');
            $table->index(['flow_route_plan_item_id', 'status'], 'tasks_route_plan_item_status_idx');
            $table->index(['flow_route_progress_item_id', 'status'], 'tasks_route_progress_item_status_idx');
            $table->index(['flow_route_id', 'flow_route_point_id', 'status'], 'tasks_route_point_status_idx');
            $table->index(['flow_route_capability_id', 'status'], 'tasks_route_capability_status_idx');
            $table->index(['task_template_id', 'status'], 'tasks_template_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
