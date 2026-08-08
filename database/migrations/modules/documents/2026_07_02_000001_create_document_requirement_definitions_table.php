<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requirement_definitions', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('category')->nullable()->index();

            $table->boolean('is_required_by_default')->default(false)->index();
            $table->boolean('allows_multiple_uploads')->default(false);
            $table->boolean('requires_review')->default(true)->index();

            $table->json('accepted_mime_types')->nullable();
            $table->unsignedInteger('max_file_size_kb')->nullable();
            $table->unsignedInteger('expires_after_days')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('settings')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category'], 'document_requirements_status_category_index');
            $table->index(['provider', 'external_id'], 'document_requirements_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requirement_definitions');
    }
};
