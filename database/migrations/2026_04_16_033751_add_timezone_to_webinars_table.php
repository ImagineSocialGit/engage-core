<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            $table->string('timezone')->default(config('app.timezone', 'America/Chicago'))->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};