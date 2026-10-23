<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_import_runs', function (Blueprint $table): void {
            // Null means a pre-upgrade run whose source is on the local disk.
            $table->string('csv_disk', 64)->nullable()->after('csv_path');
        });
    }

    public function down(): void
    {
        Schema::table('contact_import_runs', function (Blueprint $table): void {
            $table->dropColumn('csv_disk');
        });
    }
};