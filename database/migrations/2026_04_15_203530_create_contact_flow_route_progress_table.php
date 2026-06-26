<?php

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_flow_route_progress', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('contact_id')->index('cfrp_contact_id_idx');
            $table->unsignedBigInteger('contact_status_id')->index('cfrp_status_id_idx');

            $table->foreignIdFor(ContactWorkflowProfile::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(FlowRoute::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(FlowRoutePoint::class, 'current_flow_route_point_id')
                ->nullable()
                ->constrained('flow_route_points')
                ->nullOnDelete();

            $table->string('status')->index('cfrp_status_idx');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->string('cancellation_reason')->nullable();
            $table->string('failure_reason')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['contact_id', 'status'], 'cfrp_contact_status_idx');
            $table->index(['contact_workflow_profile_id', 'status'], 'cfrp_profile_status_idx');
            $table->index(['contact_status_id', 'status'], 'cfrp_contact_status_status_idx');
            $table->index(['flow_route_id', 'status'], 'cfrp_route_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_flow_route_progress');
    }
};