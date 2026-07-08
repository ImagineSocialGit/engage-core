<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
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

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->nullableMorphs('subject');

            $table->foreignIdFor(ContactStatus::class)
                ->nullable()
                ->constrained(indexName: 'cfrp_contact_status_fk')
                ->nullOnDelete();

            $table->foreignIdFor(ContactWorkflowProfile::class)
                ->nullable()
                ->constrained(indexName: 'cfrp_workflow_profile_fk')
                ->nullOnDelete();

            $table->foreignIdFor(FlowRoute::class)
                ->constrained(indexName: 'cfrp_route_fk')
                ->cascadeOnDelete();

            $table->foreignIdFor(FlowRoutePoint::class, 'current_flow_route_point_id')
                ->nullable()
                ->constrained(
                    table: 'flow_route_points',
                    indexName: 'cfrp_current_route_point_fk',
                )
                ->nullOnDelete();

            $table->string('status')->index('cfrp_status_idx');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamp('resume_at')->nullable()->index('cfrp_resume_at_idx');
            $table->string('waiting_event_key')->nullable()->index('cfrp_waiting_event_key_idx');

            $table->string('cancellation_reason')->nullable();
            $table->string('failure_reason')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['contact_id', 'status'], 'cfrp_contact_status_idx');
            $table->index(['contact_id', 'subject_type', 'subject_id', 'status'], 'cfrp_contact_subject_status_idx');
            $table->index(['contact_workflow_profile_id', 'status'], 'cfrp_profile_status_idx');
            $table->index(['contact_status_id', 'status'], 'cfrp_contact_status_status_idx');
            $table->index(['flow_route_id', 'status'], 'cfrp_route_status_idx');
            $table->index(['flow_route_id', 'subject_type', 'subject_id', 'status'], 'cfrp_route_subject_status_idx');
            $table->index(['status', 'resume_at'], 'cfrp_status_resume_idx');
            $table->index(['contact_id', 'status', 'waiting_event_key'], 'cfrp_contact_waiting_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_flow_route_progress');
    }
};
