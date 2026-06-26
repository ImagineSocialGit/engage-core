<?php

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_consents', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();

            $table->string('channel');
            $table->string('purpose');
            $table->string('scope');

            $table->timestamp('consented_at');

            $table->string('source')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique([
                'contact_id',
                'channel',
                'purpose',
                'scope',
            ], 'message_consents_contact_channel_purpose_scope_unique');

            $table->index(['contact_id', 'channel', 'purpose', 'scope'], 'message_consents_lookup_index');
            $table->index(['channel', 'purpose', 'scope'], 'message_consents_channel_purpose_scope_index');
            $table->index('consented_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_consents');
    }
};