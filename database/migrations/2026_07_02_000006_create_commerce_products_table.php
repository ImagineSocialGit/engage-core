<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_products', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_products');
    }
};
