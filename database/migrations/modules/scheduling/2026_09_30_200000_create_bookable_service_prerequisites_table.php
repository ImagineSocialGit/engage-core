<?php

use App\Modules\Scheduling\Models\BookableService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookable_service_prerequisites', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(BookableService::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('prerequisite_bookable_service_id')
                ->constrained('bookable_services', indexName: 'bookable_service_prerequisite_required_service_fk')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('required_completions')->default(1);
            $table->unsignedInteger('valid_for_days')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->string('source')->default('manual')->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(
                ['bookable_service_id', 'prerequisite_bookable_service_id'],
                'bookable_service_prerequisites_unique',
            );

            $table->index(
                ['bookable_service_id', 'is_active', 'sort_order'],
                'bookable_service_prerequisites_active_sort_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookable_service_prerequisites');
    }
};