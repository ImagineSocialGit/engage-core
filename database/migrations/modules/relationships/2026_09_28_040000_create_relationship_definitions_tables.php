<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_definitions', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->string('singular', 120);
            $table->string('plural', 120);
            $table->boolean('visible')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('relationship_stage_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('relationship_key', 120);
            $table->string('key', 120);
            $table->string('label', 120);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['relationship_key', 'key'], 'relationship_stage_def_uq');
            $table->foreign('relationship_key', 'relationship_stage_def_type_fk')
                ->references('key')->on('relationship_definitions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_stage_definitions');
        Schema::dropIfExists('relationship_definitions');
    }
};