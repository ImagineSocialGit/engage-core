<?php

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

            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('campaign_key', 120)->index();

            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 120)->index();

            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('current_step')->nullable();

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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_enrollments');
    }
};