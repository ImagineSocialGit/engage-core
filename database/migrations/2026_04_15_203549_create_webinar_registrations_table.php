<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webinar_registrations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();

            $table->string('join_token')->unique();

            $table->string('webinar_slug')->default('default')->index();
            $table->string('status')->default('pending')->index();
            $table->string('source')->default('webinar_subdomain')->index();

            $table->json('meta')->nullable();

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('attended_at')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webinar_registrations');
    }
};
