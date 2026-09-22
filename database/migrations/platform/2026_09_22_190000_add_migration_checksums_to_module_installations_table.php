<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_installations', function (Blueprint $table): void {
            $table->json('migration_checksums')
                ->nullable()
                ->after('manifest_hash');
        });
    }

    public function down(): void
    {
        Schema::table('module_installations', function (Blueprint $table): void {
            $table->dropColumn('migration_checksums');
        });
    }
};