<?php

use App\Modules\InboundMessaging\Models\InboundMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_message_receipts', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(InboundMessage::class)
                ->nullable()
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('client_key')->nullable()->index();
            $table->string('provider')->index();
            $table->string('provider_event_id')->nullable()->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->char('provider_event_key', 64)
                ->nullable()
                ->unique('inbound_receipts_provider_event_key_unique');
            $table->char('provider_message_key', 64)
                ->nullable()
                ->unique('inbound_receipts_provider_message_key_unique');

            $table->string('status')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('response_message')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_message_receipts');
    }
};