<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_acknowledgements', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(User::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('surface')->default('crm_dashboard')->index();
            $table->string('item_type')->index();
            $table->string('item_key')->index();

            $table->timestamp('acknowledged_at')->index();
            $table->timestamp('expires_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(
                ['user_id', 'surface', 'item_type', 'item_key'],
                'dashboard_acknowledgements_unique_item'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_acknowledgements');
    }
};
