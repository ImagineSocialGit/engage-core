<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_registration_id')->constrained()->cascadeOnDelete();
            $table->string('channel'); // email | sms
            $table->string('message_type'); // reminder_10d, etc.
            $table->timestamp('scheduled_for');
            $table->timestamps();

            $table->unique([
                'webinar_registration_id',
                'channel',
                'message_type',
            ], 'webinar_sched_msgs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_scheduled_messages');
    }
};