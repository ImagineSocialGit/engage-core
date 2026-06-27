<?php

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Campaign::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('step_number');
            $table->string('name')->nullable();
            $table->string('dispatch_key', 191);
            $table->boolean('is_active')->default(true)->index();

            $table->json('criteria')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['campaign_id', 'step_number']);
            $table->index(['campaign_id', 'is_active', 'step_number']);
            $table->index('dispatch_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_steps');
    }
};