<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('recipient');

            $table->string('client_key')->nullable()->index();

            $table->string('channel')->default('sms')->index();
            $table->string('provider')->index();

            $table->string('provider_event_id')->nullable()->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('provider_context_id')->nullable()->index();

            $table->string('from_type')->nullable()->index();
            $table->string('from_value')->nullable()->index();

            $table->string('to_type')->nullable()->index();
            $table->string('to_value')->nullable()->index();

            $table->text('body')->nullable();

            $table->string('classification')->index();
            $table->string('purpose')->nullable()->index();
            $table->string('scope')->nullable()->index();

            $table->timestamp('received_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index([
                'recipient_type',
                'recipient_id',
                'provider',
                'classification',
            ], 'inbound_messages_recipient_provider_classification_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_messages');
    }
};