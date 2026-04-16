<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('webinar_slug')->default('default')->index();
            $table->string('status')->default('pending')->index();
            $table->string('source')->default('webinar_subdomain')->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->index();
            $table->string('phone')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_registrations');
    }
};