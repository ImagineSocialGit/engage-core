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
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('task_title_template');
            $table->text('task_description_template')->nullable();

            $table->integer('default_due_offset_days')->nullable();

            $table->string('default_assignment_strategy')->nullable();
            $table->nullableMorphs('default_assigned_to');

            $table->json('default_cancel_conditions')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points');
    }
};