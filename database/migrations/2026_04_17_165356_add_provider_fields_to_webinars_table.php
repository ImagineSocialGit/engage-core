<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            // Provider-specific identifiers
            $table->string('external_id')
                ->nullable()
                ->after('platform')
                ->index();

            // Supports multiple connected accounts per platform (future-proofing)
            $table->string('host_account_key')
                ->nullable()
                ->after('external_id')
                ->index();

            // Optional provider-hosted registration URL
            $table->text('registration_url')
                ->nullable()
                ->after('join_url');

            // Flexible provider-specific config (passcodes, settings, flags, etc.)
            $table->json('provider_settings')
                ->nullable()
                ->after('meta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            Schema::table('webinars', function (Blueprint $table) {
                $table->dropColumn([
                    'external_id',
                    'host_account_key',
                    'registration_url',
                    'provider_settings',
                ]);
            });
        });
    }
};
