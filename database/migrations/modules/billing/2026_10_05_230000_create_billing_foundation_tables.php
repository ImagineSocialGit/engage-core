<?php

use App\Modules\Billing\Models\BillingPaymentRequest;
use App\Modules\Billing\Models\BillingPrice;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_prices', function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->string('key', 120)->default('default');
            $table->string('status', 40)->default('active')->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_cents');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['billable_type', 'billable_id', 'key'],
                'billing_prices_billable_key_unique',
            );
        });

        Schema::create('billing_collection_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(BillingPrice::class)
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('mode', 40)->index();
            $table->unsignedBigInteger('fixed_amount_cents')->nullable();
            $table->unsignedSmallInteger('percentage_basis_points')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(BillingPrice::class)
                ->constrained()
                ->restrictOnDelete();
            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->nullableMorphs('context');
            $table->string('status', 40)->default('open')->index();
            $table->string('collection_mode', 40)->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('due_now_cents');
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->timestamp('settled_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(
                ['contact_id', 'status', 'created_at'],
                'billing_payment_requests_contact_status_created_index',
            );
            $table->index(
                ['billing_price_id', 'status'],
                'billing_payment_requests_price_status_index',
            );
        });

        Schema::create('billing_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(BillingPaymentRequest::class)
                ->constrained()
                ->restrictOnDelete();
            $table->string('provider_key', 120);
            $table->string('external_id', 191);
            $table->string('status', 40)->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('occurred_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider_key', 'external_id'],
                'billing_payments_provider_external_unique',
            );
            $table->index(
                ['billing_payment_request_id', 'status'],
                'billing_payments_request_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_payment_requests');
        Schema::dropIfExists('billing_collection_requirements');
        Schema::dropIfExists('billing_prices');
    }
};