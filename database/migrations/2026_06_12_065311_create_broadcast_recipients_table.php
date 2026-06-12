<?php

use App\Models\Broadcast;
use App\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Broadcast::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->json('scheduled_message_ids')->nullable();
            $table->string('skip_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['broadcast_id', 'contact_id']);
            $table->index(['broadcast_id', 'status']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
    }
};