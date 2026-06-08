<?php

use App\Models\Contact;
use App\Models\MessageConsent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_revocations', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(MessageConsent::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('channel');
            $table->string('purpose');
            $table->string('scope');

            $table->string('reason')->nullable();

            $table->timestamp('revoked_at');

            $table->string('source')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index([
                'contact_id',
                'channel',
                'purpose',
                'scope',
            ], 'consent_revocations_lookup_index');

            $table->index([
                'channel',
                'purpose',
                'scope',
            ], 'consent_revocations_channel_purpose_scope_index');

            $table->index('message_consent_id');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_revocations');
    }
};