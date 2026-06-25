<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mortgage_stages', function (Blueprint $table) {
            $table->id();

            $table->string('name')->unique();

            $table->string('category')->index();

            $table->unsignedSmallInteger('sort_order')
                ->default(0)
                ->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mortgage_stages');
    }
};