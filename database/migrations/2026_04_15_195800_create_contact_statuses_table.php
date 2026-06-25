<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_statuses', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('name');
            $table->string('category')->nullable()->index();

            $table->boolean('is_core')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();

            $table->unsignedSmallInteger('sort_order')->default(0)->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_statuses');
    }
};