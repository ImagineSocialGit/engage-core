<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('key', 120)->unique();

            $table->string('name');
            $table->text('description')->nullable();

            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 120)->index();

            $table->string('status', 32)->default('active')->index();

            $table->string('source_version')->nullable();

            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['channel', 'purpose', 'scope', 'status']);
            $table->index(['key', 'status']);
            $table->index(['key', 'source_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};