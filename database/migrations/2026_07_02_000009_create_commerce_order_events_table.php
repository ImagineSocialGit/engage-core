<?php

use App\Modules\Commerce\Models\CommerceOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_order_events', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(CommerceOrder::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->nullableMorphs('actor');

            $table->string('event')->index();
            $table->string('from_status')->nullable()->index();
            $table->string('to_status')->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();

            $table->string('source')->default('provider')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();

            $table->json('payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['commerce_order_id', 'event'], 'commerce_order_events_order_event_index');
            $table->index(['provider', 'external_id'], 'commerce_order_events_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_events');
    }
};
