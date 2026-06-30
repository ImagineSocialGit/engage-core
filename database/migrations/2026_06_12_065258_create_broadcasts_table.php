<?php

use App\Models\User;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('channel');
            $table->string('purpose');
            $table->string('scope');
            $table->string('dispatch_key')->default('broadcast_send');
            $table->string('message_type')->default('broadcast');
            $table->string('payload_class')->default(EmailPayload::class);
            $table->string('queue')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('send_at')->nullable();
            $table->json('payload')->nullable();
            $table->json('audience')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('scheduled_count')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
            $table->index(['channel', 'purpose', 'scope']);
            $table->index('dispatch_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};