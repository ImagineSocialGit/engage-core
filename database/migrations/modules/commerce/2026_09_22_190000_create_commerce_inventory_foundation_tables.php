<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};