<?php

use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_message_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ScheduledMessage::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->uuid('claim_token')->unique();
            $table->string('status', 32)->index();
            $table->timestamp('claimed_at');
            $table->timestamp('lease_expires_at');
            $table->timestamp('provider_submission_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('destination', 255)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->string('reason_code', 96)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['scheduled_message_id', 'attempt_number'],
                'scheduled_message_delivery_attempt_number_unique',
            );
            $table->index(
                ['scheduled_message_id', 'status'],
                'scheduled_message_delivery_attempt_status_index',
            );
            $table->index(
                ['status', 'lease_expires_at'],
                'scheduled_message_delivery_attempt_stale_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_message_delivery_attempts');
    }
};