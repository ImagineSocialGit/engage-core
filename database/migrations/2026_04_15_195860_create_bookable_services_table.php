<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookable_services', function (Blueprint $table) {
            $table->id();

            $table->string('key')->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('status')->default('active')->index();

            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);

            $table->string('location_type')->nullable()->index();
            $table->json('location_details')->nullable();

            $table->unsignedInteger('capacity')->nullable();

            $table->boolean('requires_confirmation')->default(false)->index();
            $table->boolean('is_public')->default(false)->index();

            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_public', 'sort_order'], 'bookable_services_visibility_index');
            $table->index(['provider', 'external_id'], 'bookable_services_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookable_services');
    }
};
