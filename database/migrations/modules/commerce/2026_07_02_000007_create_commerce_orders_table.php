<?php

use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(CommerceCustomer::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('order_number')->nullable()->index();
            $table->string('order_name')->nullable()->index();

            $table->string('status')->default('open')->index();
            $table->string('financial_status')->nullable()->index();
            $table->string('fulfillment_status')->nullable()->index();

            $table->string('currency', 3)->nullable()->index();
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('shipping_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);

            $table->timestamp('ordered_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->timestamp('refunded_at')->nullable()->index();

            $table->string('source')->default('provider')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('raw_payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['commerce_customer_id', 'status'], 'commerce_orders_customer_status_index');
            $table->index(['contact_id', 'status', 'ordered_at'], 'commerce_orders_contact_status_ordered_index');
            $table->index(['status', 'ordered_at'], 'commerce_orders_status_ordered_index');
            $table->index(['financial_status', 'ordered_at'], 'commerce_orders_financial_ordered_index');
            $table->index(['provider', 'external_id'], 'commerce_orders_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_orders');
    }
};
