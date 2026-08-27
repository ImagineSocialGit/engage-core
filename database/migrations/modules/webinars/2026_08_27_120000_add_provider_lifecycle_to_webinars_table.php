<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->string('provider_lifecycle_status')
                ->default('active')
                ->after('host_account_key');
            $table->timestamp('provider_missing_at')
                ->nullable()
                ->after('provider_lifecycle_status');
            $table->timestamp('provider_archived_at')
                ->nullable()
                ->after('provider_missing_at');

            $table->index(
                ['provider_lifecycle_status', 'starts_at'],
                'webinars_provider_lifecycle_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropIndex('webinars_provider_lifecycle_index');
            $table->dropColumn([
                'provider_lifecycle_status',
                'provider_missing_at',
                'provider_archived_at',
            ]);
        });
    }
};