<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_inbox_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('client_key')->nullable()->index();
            $table->string('provider')->index();
            $table->string('provider_event_id')->nullable();
            $table->char('signature_fingerprint', 64)->nullable();
            $table->char('receipt_key', 64)
                ->unique('webhook_inbox_receipt_key_unique');
            $table->string('event_type')->nullable()->index();
            $table->char('payload_fingerprint', 64);
            $table->json('payload');
            $table->string('status')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable()->index();
            $table->timestamp('claim_expires_at')->nullable()->index();
            $table->timestamp('last_attempted_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->json('outcome')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['provider', 'provider_event_id'],
                'webhook_inbox_provider_event_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_inbox_receipts');
    }
};
