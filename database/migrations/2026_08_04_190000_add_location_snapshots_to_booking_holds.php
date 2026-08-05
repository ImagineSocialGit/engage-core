<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_holds', function (Blueprint $table): void {
            $table->string('location_type', 80)
                ->nullable()
                ->after('capacity');
            $table->json('location_details')
                ->nullable()
                ->after('location_type');
        });
    }

    public function down(): void
    {
        Schema::table('booking_holds', function (Blueprint $table): void {
            $table->dropColumn([
                'location_type',
                'location_details',
            ]);
        });
    }
};