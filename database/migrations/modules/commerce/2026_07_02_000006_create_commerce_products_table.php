<?php

use App\Modules\Commerce\Models\CommerceProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_products', function (Blueprint $table): void {
            $table->id();

            $table->string('key')->nullable()->unique();
            $table->string('sku')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('status')->default('active')->index();
            $table->string('product_type')->nullable()->index();
            $table->string('vendor')->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->json('tags')->nullable();

            $table->string('currency', 3)->nullable()->index();
            $table->bigInteger('price_cents')->nullable();
            $table->timestamp('published_at')->nullable()->index();

            $table->string('source')->default('provider')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('raw_payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'product_type'], 'commerce_products_status_product_type_index');
            $table->index(['provider', 'external_id'], 'commerce_products_provider_external_index');
            $table->index(['vendor', 'status'], 'commerce_products_vendor_status_index');
        });

        Schema::create('commerce_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(CommerceProduct::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('key')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('title');
            $table->string('status')->default('active')->index();
            $table->json('options')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['commerce_product_id', 'key'],
                'commerce_variants_product_key_unique',
            );
            $table->index(
                ['commerce_product_id', 'status', 'position'],
                'commerce_variants_product_status_position_index',
            );
        });

        Schema::create('commerce_product_provider_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_product_id');

            $table->string('provider_key', 120);
            $table->string('reference_type', 80);
            $table->string('external_id');
            $table->string('external_parent_id')->nullable();
            $table->string('external_url')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign(
                'commerce_product_id',
                'commerce_product_provider_map_product_fk',
            )
                ->references('id')
                ->on('commerce_products')
                ->cascadeOnDelete();

            $table->unique(
                ['provider_key', 'reference_type', 'external_id'],
                'commerce_product_maps_provider_ref_external_unique',
            );
            $table->unique(
                ['commerce_product_id', 'provider_key', 'reference_type'],
                'commerce_product_maps_product_provider_ref_unique',
            );
        });

        Schema::create('commerce_product_variant_provider_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_product_variant_id');

            $table->string('provider_key', 120);
            $table->string('reference_type', 80);
            $table->string('external_id');
            $table->string('external_parent_id')->nullable();
            $table->string('external_url')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign(
                'commerce_product_variant_id',
                'commerce_variant_provider_map_variant_fk',
            )
                ->references('id')
                ->on('commerce_product_variants')
                ->cascadeOnDelete();

            $table->unique(
                ['provider_key', 'reference_type', 'external_id'],
                'commerce_variant_maps_provider_ref_external_unique',
            );
            $table->unique(
                ['commerce_product_variant_id', 'provider_key', 'reference_type'],
                'commerce_variant_maps_variant_provider_ref_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_product_variant_provider_mappings');
        Schema::dropIfExists('commerce_product_provider_mappings');
        Schema::dropIfExists('commerce_product_variants');
        Schema::dropIfExists('commerce_products');
    }
};