<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_message_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_message_id')
                ->unique('scheduled_message_outbox_events_message_unique')
                ->constrained('scheduled_messages')
                ->cascadeOnDelete();
            $table->string('event_type', 32)->index();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('available_at')->index();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                'claim_token',
                'scheduled_message_outbox_events_claim_token_unique',
            );
            $table->index(
                ['status', 'available_at'],
                'scheduled_message_outbox_events_pending_index',
            );
            $table->index(
                ['status', 'claim_expires_at'],
                'scheduled_message_outbox_events_stale_claim_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_message_outbox_events');
    }
};