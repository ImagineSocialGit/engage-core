<?php

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookable_slot_offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('offer_id')->unique();
            $table->foreignIdFor(BookableService::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(SchedulingHost::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('display_timezone', 100);
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('remaining_capacity');
            $table->json('source_scopes')->nullable();
            $table->json('source_window_ids')->nullable();
            $table->dateTime('issued_at');
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('expires_at');
            $table->index('consumed_at');
            $table->index(
                ['bookable_service_id', 'scheduling_host_id', 'starts_at', 'ends_at'],
                'slot_offers_target_time_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookable_slot_offers');
    }
};