<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table): void {
            $table->id();

            $table->morphs('recipient');
            $table->nullableMorphs('context');
            $table->nullableMorphs('behavior_owner');

            $table->string('channel')->index();
            $table->string('message_type')->index();

            $table->string('purpose')->index();
            $table->string('scope')->index();

            $table->string('payload_class');
            $table->string('queue')->nullable()->index();

            $table->json('dispatch_keys')->nullable();
            $table->string('definition_config_path')->nullable()->index();

            $table->json('payload');

            $table->timestamp('send_at')->index();

            $table->string('status')
                ->default('pending')
                ->index();

            $table->timestamp('sending_at')->nullable()->index();
            $table->timestamp('last_attempted_at')->nullable()->index();
            $table->unsignedInteger('send_attempts')->default(0);

            $table->string('provider', 64)->nullable()->index();
            $table->string('provider_message_id', 191)->nullable()->index();

            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('skipped_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();

            $table->string('dedupe_key')
                ->nullable()
                ->unique();

            $table->text('failure_reason')->nullable();
            $table->text('skip_reason')->nullable();

            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->string('provider_idempotency_key', 128)
                ->nullable();
            $table->timestamp('provider_submission_started_at')
                ->nullable();
            $table->timestamp('recovered_at')
                ->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique('claim_token', 'scheduled_messages_claim_token_unique');
            $table->unique(
                'provider_idempotency_key',
                'scheduled_messages_provider_idempotency_key_unique',
            );
            $table->index(
                ['status', 'claim_expires_at'],
                'scheduled_messages_stale_claim_index',
            );
            $table->index(
                ['status', 'recovered_at'],
                'scheduled_messages_recovered_pending_index',
            );

            $table->index([
                'channel',
                'purpose',
                'scope',
            ], 'scheduled_messages_channel_purpose_scope_index');

            $table->index([
                'context_type',
                'context_id',
                'channel',
                'message_type',
            ], 'scheduled_messages_context_channel_type_index');

            $table->index([
                'status',
                'send_at',
            ], 'scheduled_messages_status_send_at_index');

            $table->index([
                'queue',
                'status',
                'send_at',
            ], 'scheduled_messages_queue_status_send_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
