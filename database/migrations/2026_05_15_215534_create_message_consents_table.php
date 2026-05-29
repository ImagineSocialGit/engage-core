<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_consents', function (Blueprint $table) {
            $table->id();

            $table->morphs('recipient');

            $table->string('channel');
            $table->string('purpose');

            $table->timestamp('consented_at');

            $table->string('source')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique([
                'recipient_type',
                'recipient_id',
                'channel',
                'purpose',
            ], 'message_consents_recipient_channel_purpose_unique');

            $table->index(['channel', 'purpose']);
            $table->index('consented_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_consents');
    }
};