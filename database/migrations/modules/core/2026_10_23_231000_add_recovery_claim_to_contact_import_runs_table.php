<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_import_runs', function (Blueprint $table): void {
            $table->timestamp('recovery_dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_import_runs', function (Blueprint $table): void {
            $table->dropColumn('recovery_dispatched_at');
        });
    }
};