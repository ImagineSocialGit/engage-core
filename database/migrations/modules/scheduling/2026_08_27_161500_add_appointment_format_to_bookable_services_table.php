<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookable_services', function (Blueprint $table): void {
            $table->string('appointment_format', 32)
                ->nullable()
                ->after('timezone');
            $table->string('in_person_arrangement', 32)
                ->nullable()
                ->after('appointment_format');
            $table->string('remote_method', 32)
                ->nullable()
                ->after('in_person_arrangement');
        });

        DB::table('bookable_services')
            ->where('location_type', 'phone')
            ->update([
                'appointment_format' => 'remote',
                'in_person_arrangement' => null,
                'remote_method' => 'phone',
            ]);

        DB::table('bookable_services')
            ->where('location_type', 'virtual')
            ->update([
                'appointment_format' => 'remote',
                'in_person_arrangement' => null,
                'remote_method' => 'virtual_meeting',
            ]);

        DB::table('bookable_services')
            ->where('location_type', 'fixed')
            ->update([
                'appointment_format' => 'in_person',
                'in_person_arrangement' => 'business_location',
                'remote_method' => null,
            ]);

        DB::table('bookable_services')
            ->where('location_type', 'customer_site')
            ->update([
                'appointment_format' => 'in_person',
                'in_person_arrangement' => 'customer_address',
                'remote_method' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('bookable_services', function (Blueprint $table): void {
            $table->dropColumn([
                'appointment_format',
                'in_person_arrangement',
                'remote_method',
            ]);
        });
    }
};