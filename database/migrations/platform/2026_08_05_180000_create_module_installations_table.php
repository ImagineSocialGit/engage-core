<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_installations', function (Blueprint $table) {
            $table->string('module_key', 80)->primary();
            $table->string('status', 20)->index();
            $table->unsignedInteger('schema_version');
            $table->char('manifest_hash', 64);
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_migrated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_installations');
    }
};