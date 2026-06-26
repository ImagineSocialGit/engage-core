<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('points', function (Blueprint $table) {
            $table->id();

            $table->string('key')->nullable()->unique();
            $table->string('type')->index();

            $table->string('name');
            $table->text('description')->nullable();

            $table->json('default_definition')->nullable();
            $table->json('default_settings')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->string('preset_key')->nullable()->index();
            $table->string('source_version')->nullable();

            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index(['preset_key', 'source_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points');
    }
};