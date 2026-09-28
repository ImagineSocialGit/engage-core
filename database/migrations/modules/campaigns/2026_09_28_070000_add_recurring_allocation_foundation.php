<?php

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('execution_strategy', 48)
                ->default('sequence')
                ->index();
            $table->json('allocation_settings')->nullable();
        });

        Schema::create('campaign_allocation_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('start_message_step_key', 128)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('started_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(
                ['campaign_id', 'status', 'started_at'],
                'camp_alloc_enroll_campaign_status_idx',
            );
            $table->index(
                ['contact_id', 'status', 'started_at'],
                'camp_alloc_enroll_contact_status_idx',
            );
            $table->index(
                ['source_type', 'source_id'],
                'camp_alloc_enroll_source_idx',
            );
        });

        Schema::create('campaign_allocation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->string('run_key', 191)->unique();
            $table->string('status', 32)->default('scheduled')->index();
            $table->timestamp('scheduled_for')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(
                ['campaign_id', 'status', 'scheduled_for'],
                'camp_alloc_run_campaign_status_idx',
            );
        });

        Schema::create('campaign_allocation_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('campaign_allocation_run_id');
            $table->unsignedBigInteger('campaign_allocation_enrollment_id');

            // Messaging owns MessageChainVersion and ScheduledMessage. These remain
            // logical cross-module references, matching the existing Campaign bridge.
            $table->unsignedBigInteger('message_chain_version_id');
            $table->string('message_step_key', 128);
            $table->unsignedBigInteger('scheduled_message_id')->nullable();

            $table->timestamp('assigned_at')->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign(
                'campaign_allocation_run_id',
                'camp_alloc_assign_run_fk',
            )->references('id')->on('campaign_allocation_runs')->cascadeOnDelete();
            $table->foreign(
                'campaign_allocation_enrollment_id',
                'camp_alloc_assign_enrollment_fk',
            )->references('id')->on('campaign_allocation_enrollments')->cascadeOnDelete();

            $table->unique(
                ['campaign_id', 'contact_id', 'message_step_key'],
                'camp_alloc_assign_contact_step_uq',
            );
            $table->unique(
                ['scheduled_message_id'],
                'camp_alloc_assign_sched_msg_uq',
            );
            $table->index(
                ['campaign_id', 'contact_id', 'assigned_at'],
                'camp_alloc_assign_campaign_contact_idx',
            );
            $table->index(
                ['campaign_id', 'sent_at'],
                'camp_alloc_assign_campaign_sent_idx',
            );
        });

        Schema::create('campaign_allocation_message_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->string('message_step_key', 128);
            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignIdFor(User::class, 'excluded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['contact_id', 'campaign_id', 'message_step_key'],
                'camp_alloc_excl_contact_step_uq',
            );
            $table->index(
                ['campaign_id', 'message_step_key'],
                'camp_alloc_excl_campaign_step_idx',
            );
            $table->index(
                ['source_type', 'source_id'],
                'camp_alloc_excl_source_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_allocation_message_exclusions');
        Schema::dropIfExists('campaign_allocation_assignments');
        Schema::dropIfExists('campaign_allocation_runs');
        Schema::dropIfExists('campaign_allocation_enrollments');

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex('campaigns_execution_strategy_index');
            $table->dropColumn(['execution_strategy', 'allocation_settings']);
        });
    }
};