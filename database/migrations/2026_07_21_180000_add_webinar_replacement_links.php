<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->foreignId('replacement_of_webinar_id')
                ->nullable()
                ->after('webinar_series_id')
                ->unique('webinars_replacement_of_unique')
                ->constrained('webinars')
                ->nullOnDelete();
        });

        Schema::table('webinar_registrations', function (Blueprint $table): void {
            $table->foreignId('replacement_of_registration_id')
                ->nullable()
                ->after('webinar_id')
                ->unique('webinar_registrations_replacement_of_unique')
                ->constrained('webinar_registrations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table): void {
            $table->dropForeign(['replacement_of_registration_id']);
            $table->dropUnique('webinar_registrations_replacement_of_unique');
            $table->dropColumn('replacement_of_registration_id');
        });

        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropForeign(['replacement_of_webinar_id']);
            $table->dropUnique('webinars_replacement_of_unique');
            $table->dropColumn('replacement_of_webinar_id');
        });
    }
};