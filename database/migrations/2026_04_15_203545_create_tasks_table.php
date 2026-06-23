<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->morphs('assigned_to');
            $table->nullableMorphs('related');

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status')->default('open')->index();

            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();

            $table->timestamps();

            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};