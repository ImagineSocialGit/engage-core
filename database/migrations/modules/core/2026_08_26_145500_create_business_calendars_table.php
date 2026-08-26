<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_calendars', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->json('skipped_weekdays');
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('business_calendar_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_calendar_id');
            $table->uuid('key');
            $table->string('name');
            $table->string('recurrence', 16);
            $table->date('exact_date')->nullable();
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->timestamps();

            $table->foreign('business_calendar_id', 'business_cal_exclusions_calendar_fk')
                ->references('id')
                ->on('business_calendars')
                ->cascadeOnDelete();
            $table->unique(
                ['business_calendar_id', 'key'],
                'business_cal_exclusions_calendar_key_unique',
            );
        });

        $now = now();

        DB::table('business_calendars')->insert([
            'key' => 'default',
            'name' => 'Business days',
            'skipped_weekdays' => json_encode([6, 7], JSON_THROW_ON_ERROR),
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_calendar_exclusions');
        Schema::dropIfExists('business_calendars');
    }
};