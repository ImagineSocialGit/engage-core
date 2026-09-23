<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_message_delivery_attempts', function (Blueprint $table): void {
            $table->index(
                ['provider', 'provider_message_id'],
                'sm_delivery_attempt_provider_message_idx',
            );
        });

        Schema::create('scheduled_message_email_open_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_message_id');

            $table->foreign(
                'scheduled_message_id',
                'sm_email_open_signal_message_fk',
            )
                ->references('id')
                ->on('scheduled_messages')
                ->cascadeOnDelete();
            $table->foreignId('delivery_attempt_id')
                ->unique('sm_email_open_signal_attempt_unique')
                ->constrained('scheduled_message_delivery_attempts')
                ->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('provider_message_id', 191);
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_occurred_at');
            $table->timestamp('last_occurred_at');

            $table->index(
                ['first_occurred_at', 'scheduled_message_id'],
                'sm_email_open_signal_first_message_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_message_email_open_signals');

        Schema::table('scheduled_message_delivery_attempts', function (Blueprint $table): void {
            $table->dropIndex('sm_delivery_attempt_provider_message_idx');
        });
    }
};