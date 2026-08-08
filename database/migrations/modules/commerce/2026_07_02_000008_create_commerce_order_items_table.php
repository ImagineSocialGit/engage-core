<?php

use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(CommerceOrder::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(CommerceProduct::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('item_type')->default('product')->index();
            $table->string('sku')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('title')->nullable()->index();
            $table->string('variant_title')->nullable()->index();
            $table->json('options')->nullable();

            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('currency', 3)->nullable()->index();
            $table->bigInteger('unit_price_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);

            $table->string('fulfillment_status')->nullable()->index();

            $table->string('source')->default('provider')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_product_id')->nullable()->index();
            $table->string('external_variant_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('raw_payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['commerce_order_id', 'item_type'], 'commerce_order_items_order_type_index');
            $table->index(['commerce_product_id', 'item_type'], 'commerce_order_items_product_type_index');
            $table->index(['provider', 'external_id'], 'commerce_order_items_provider_external_index');
            $table->index(['provider', 'external_product_id'], 'commerce_order_items_provider_product_index');
            $table->index(['provider', 'external_variant_id'], 'commerce_order_items_provider_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_items');
    }
};
