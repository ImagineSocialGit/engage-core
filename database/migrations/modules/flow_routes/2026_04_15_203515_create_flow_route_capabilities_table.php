<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_route_capabilities', function (Blueprint $table) {
            $table->id();

            $table->string('key', 191)->unique();
            $table->string('module_key', 120)->index();
            $table->string('capability_type', 40)->index();
            $table->string('point_type', 80)->index();
            $table->string('handler_key', 120)->nullable()->index();
            $table->string('event_key', 191)->nullable()->index();
            $table->string('action_key', 191)->nullable()->index();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable()->index();
            $table->string('surface')->nullable()->index();

            $table->json('supported_subjects')->nullable();
            $table->json('required_modules')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->json('available_fields')->nullable();
            $table->json('defaults')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->string('source')->default('preset')->index();
            $table->string('source_version')->nullable();

            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['module_key', 'capability_type', 'is_active'], 'frc_module_type_active_idx');
            $table->index(['module_key', 'point_type', 'is_active'], 'frc_module_point_active_idx');
            $table->index(['capability_type', 'point_type', 'is_active'], 'frc_type_point_active_idx');
            $table->index(['key', 'source_version'], 'frc_key_source_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_route_capabilities');
    }
};
