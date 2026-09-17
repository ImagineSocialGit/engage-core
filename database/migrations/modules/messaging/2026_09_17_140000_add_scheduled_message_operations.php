<?php

use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->string('operational_state', 24)->default('active')->index();
            $table->timestamp('manual_schedule_override_at')->nullable();
        });

        Schema::create('scheduled_message_operational_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ScheduledMessage::class);
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_email', 255);
            $table->string('action', 32);
            $table->text('reason')->nullable();
            $table->timestamp('previous_send_at')->nullable();
            $table->timestamp('current_send_at')->nullable();
            $table->string('previous_operational_state', 24);
            $table->string('current_operational_state', 24);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['scheduled_message_id', 'id'],
                'scheduled_message_operations_message_id_index',
            );
            $table->index(['actor_id', 'occurred_at'], 'scheduled_message_operations_actor_time_index');
            $table->foreign('scheduled_message_id', 'sm_operational_event_message_fk')
                ->references('id')
                ->on('scheduled_messages')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_message_operational_events');
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->dropIndex(['operational_state']);
            $table->dropColumn(['operational_state', 'manual_schedule_override_at']);
        });
    }
};