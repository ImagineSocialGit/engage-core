<?php

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_customers', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();

            $table->string('status')->default('active')->index();
            $table->string('currency', 3)->nullable()->index();

            $table->timestamp('first_ordered_at')->nullable()->index();
            $table->timestamp('last_ordered_at')->nullable()->index();
            $table->unsignedInteger('total_orders')->default(0);
            $table->bigInteger('total_spent_cents')->default(0);

            $table->string('source')->default('provider')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('raw_payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_id', 'status'], 'commerce_customers_contact_status_index');
            $table->index(['provider', 'external_id'], 'commerce_customers_provider_external_index');
            $table->index(['status', 'last_ordered_at'], 'commerce_customers_status_last_ordered_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_customers');
    }
};
