<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            $table->string('key')->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('label')->nullable();
            $table->string('type')->default('address')->index();
            $table->string('status')->default('active')->index();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable()->index();
            $table->string('region')->nullable()->index();
            $table->string('postal_code')->nullable()->index();
            $table->string('country', 2)->nullable()->index();
            $table->string('formatted_address')->nullable();

            $table->decimal('latitude', 10, 7)->nullable()->index();
            $table->decimal('longitude', 10, 7)->nullable()->index();
            $table->string('timezone')->nullable()->index();
            $table->string('precision')->nullable()->index();
            $table->decimal('confidence', 8, 4)->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();
            $table->timestamp('geocoded_at')->nullable()->index();

            $table->json('raw_payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['country', 'region', 'city'], 'locations_country_region_city_index');
            $table->index(['provider', 'external_id'], 'locations_provider_external_index');
            $table->index(['status', 'type'], 'locations_status_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
