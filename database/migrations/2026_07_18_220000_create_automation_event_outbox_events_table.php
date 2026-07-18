<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_event_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->string('event_key', 191)->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->timestamp('occurred_at');
            $table->json('payload');
            $table->json('meta');
            $table->string('status', 32)->default('pending');
            $table->timestamp('available_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'available_at'],
                'automation_event_outbox_ready_idx',
            );
            $table->index(
                ['status', 'claim_expires_at'],
                'automation_event_outbox_stale_idx',
            );
            $table->index(
                ['subject_type', 'subject_id'],
                'automation_event_outbox_subject_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_event_outbox_events');
    }
};