<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_areas', function (Blueprint $table) {
            $table->id();

            $table->string('key')->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('service_area')->index();
            $table->string('status')->default('active')->index();
            $table->string('boundary_type')->default('manual')->index();

            $table->string('country', 2)->nullable()->index();
            $table->string('region')->nullable()->index();
            $table->string('city')->nullable()->index();
            $table->string('postal_code')->nullable()->index();

            $table->decimal('center_latitude', 10, 7)->nullable()->index();
            $table->decimal('center_longitude', 10, 7)->nullable()->index();
            $table->unsignedInteger('radius_meters')->nullable()->index();
            $table->json('geometry')->nullable();
            $table->string('timezone')->nullable()->index();
            $table->boolean('is_service_area')->default(true)->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('settings')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type'], 'location_areas_status_type_index');
            $table->index(['country', 'region', 'city'], 'location_areas_country_region_city_index');
            $table->index(['provider', 'external_id'], 'location_areas_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_areas');
    }
};
