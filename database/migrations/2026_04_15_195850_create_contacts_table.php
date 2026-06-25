<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('name')->nullable();

            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable()->index();

            $table->string('source')->nullable()->index();
            $table->string('subsource')->nullable()->index();

            $table->timestamp('last_contacted_at')->nullable()->index();
            $table->timestamp('last_activity_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['source', 'subsource']);
            $table->index(['last_activity_at', 'last_contacted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};