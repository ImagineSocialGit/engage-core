<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->index();
            $table->string('phone')->nullable()->index();
            $table->string('status')->default('new')->index();
            $table->string('source')->default('webinar')->index();
            $table->string('subsource')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};