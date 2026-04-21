<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webinar_scheduled_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('webinar_registration_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('channel')->index(); // email, sms
            $table->string('message_type')->index(); // confirmation, reminder_24h, reminder_30m, reminder_10m, late_joiner
            $table->string('status')->default('pending')->index(); // pending, sent, skipped, failed

            $table->timestamp('send_at')->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('skipped_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();

            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(
                ['webinar_registration_id', 'channel'],
                'wsm_reg_channel_idx'
            );

            $table->index(
                ['webinar_registration_id', 'message_type'],
                'wsm_reg_type_idx'
            );

            $table->unique(
                ['webinar_registration_id', 'channel', 'message_type'],
                'wsm_unique_reg_channel_type'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webinar_scheduled_messages');
    }
};