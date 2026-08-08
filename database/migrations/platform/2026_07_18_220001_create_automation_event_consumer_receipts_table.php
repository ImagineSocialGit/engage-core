<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_event_consumer_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id');
            $table->string('consumer', 191);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'consumer']);
            $table->index(['consumer', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_event_consumer_receipts');
    }
};