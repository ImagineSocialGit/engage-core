<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_permission_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(ScheduledMessage::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('context');

            $table->string('channel')->index();
            $table->string('source')->index();
            $table->string('status')->index();

            $table->timestamp('claimed_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('accepted_at')->nullable()->index();

            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique([
                'contact_id',
                'channel',
                'source',
            ], 'contact_permission_invitations_contact_channel_source_unique');

            $table->index([
                'channel',
                'source',
                'status',
            ], 'contact_permission_invitations_channel_source_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_permission_invitations');
    }
};