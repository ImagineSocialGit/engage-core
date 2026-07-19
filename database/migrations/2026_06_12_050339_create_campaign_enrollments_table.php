<?php

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
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

            $table->string('campaign_key', 120)->index();

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
                'status',
            ], 'campaign_enrollments_contact_campaign_status_index');

            $table->index([
                'source_id',
                'campaign_key',
            ], 'campaign_enrollments_source_campaign_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_enrollments');
    }
};