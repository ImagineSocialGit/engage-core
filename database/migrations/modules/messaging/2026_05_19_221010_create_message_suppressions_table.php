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
        Schema::create('message_suppressions', function (Blueprint $table) {
            $table->id();

            $table->string('channel', 32); // sms|email
            $table->string('destination', 320); // E.164 phone or lowercase email
            $table->string('reason', 64); // stop|unsubscribe|bounce|complaint|manual|provider|invalid_destination
            $table->string('provider', 64)->nullable(); // twilio|resend|null
            $table->string('source_event_id')->nullable();

            $table->timestamp('suppressed_at');
            $table->timestamp('released_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['channel', 'destination', 'released_at']);
            $table->index(['provider', 'source_event_id']);
            $table->index(['channel', 'reason']);
            $table->index('suppressed_at');
            $table->index('released_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_suppressions');
    }
};
