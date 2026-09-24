<?php

use App\Modules\Commerce\Models\CommerceOffer;
use App\Modules\Commerce\Models\CommerceProductVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_offers', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->nullable()->unique();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->string('provider_scope', 120)->nullable()->index();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('publish_starts_at')->nullable()->index();
            $table->timestamp('publish_ends_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['status', 'position'],
                'commerce_offers_status_position_index',
            );
        });

        Schema::create('commerce_offer_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(CommerceOffer::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(CommerceProductVariant::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->string('status', 40)->default('active')->index();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['commerce_offer_id', 'commerce_product_variant_id'],
                'commerce_offer_variants_offer_variant_unique',
            );
            $table->index(
                ['commerce_offer_id', 'status', 'position'],
                'commerce_offer_variants_offer_status_position_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_offer_variants');
        Schema::dropIfExists('commerce_offers');
    }
};