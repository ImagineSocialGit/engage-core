<?php

use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::table('commerce_order_items', function (Blueprint $table): void {
            $table->foreignIdFor(CommerceProductVariant::class)
                ->nullable()
                ->after('commerce_product_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::create('commerce_inventory_effects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_product_variant_id');

            $table->string('source_type', 80)->index();
            $table->string('source_key', 120)->index();
            $table->string('source_reference')->nullable()->index();
            $table->string('reason', 120)->index();
            $table->decimal('quantity_delta', 12, 4);
            $table->string('authority_mode', 80)->index();
            $table->string('inventory_scope', 120)->nullable()->index();
            $table->string('status', 80)->default('recorded')->index();
            $table->string('idempotency_key', 191)->unique();
            $table->char('payload_fingerprint', 64);
            $table->timestamp('occurred_at')->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign(
                'commerce_product_variant_id',
                'commerce_inventory_effect_variant_fk',
            )
                ->references('id')
                ->on('commerce_product_variants')
                ->restrictOnDelete();
        });

        Schema::create('commerce_inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_inventory_effect_id');
            $table->foreignId('commerce_product_variant_provider_mapping_id')->nullable();

            $table->string('provider_key', 120)->index();
            $table->decimal('quantity_delta', 12, 4);
            $table->string('status', 80)->default('pending')->index();
            $table->string('idempotency_key', 191)->unique();
            $table->string('external_id')->nullable()->index();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign(
                'commerce_inventory_effect_id',
                'commerce_inventory_adjustment_effect_fk',
            )
                ->references('id')
                ->on('commerce_inventory_effects')
                ->cascadeOnDelete();

            $table->foreign(
                'commerce_product_variant_provider_mapping_id',
                'commerce_inventory_adjustment_variant_map_fk',
            )
                ->references('id')
                ->on('commerce_product_variant_provider_mappings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_adjustments');
        Schema::dropIfExists('commerce_inventory_effects');

        Schema::table('commerce_order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commerce_product_variant_id');
        });

        Schema::dropIfExists('commerce_product_variant_provider_mappings');
        Schema::dropIfExists('commerce_product_provider_mappings');
        Schema::dropIfExists('commerce_product_variants');
    }
};