<?php

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_flow_route_plans', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(ContactFlowRouteProgress::class, 'contact_flow_route_progress_id')
                ->constrained(
                    table: 'contact_flow_route_progress',
                    indexName: 'cfrplan_progress_fk',
                )
                ->cascadeOnDelete();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->nullableMorphs('subject');

            $table->foreignIdFor(FlowRoute::class)
                ->constrained(indexName: 'cfrplan_route_fk')
                ->cascadeOnDelete();

            $table->string('status')->default('active')->index();
            $table->string('source')->default('template')->index();
            $table->unsignedInteger('flow_route_version')->nullable();
            $table->timestamp('snapshot_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->string('cancellation_reason')->nullable();
            $table->string('failure_reason')->nullable();

            $table->json('route_snapshot')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique('contact_flow_route_progress_id', 'cfrplan_progress_unique');
            $table->index(['contact_id', 'status'], 'cfrplan_contact_status_idx');
            $table->index(['contact_id', 'subject_type', 'subject_id', 'status'], 'cfrplan_contact_subject_status_idx');
            $table->index(['flow_route_id', 'status'], 'cfrplan_route_status_idx');
            $table->index(['flow_route_id', 'subject_type', 'subject_id', 'status'], 'cfrplan_route_subject_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_flow_route_plans');
    }
};
