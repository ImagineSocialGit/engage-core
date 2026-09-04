<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_email_routes', function (Blueprint $table): void {
            $table->boolean('contact_extraction_enabled')
                ->default(false)
                ->after('is_active');
            $table->json('contact_extraction_definition')
                ->nullable()
                ->after('contact_extraction_enabled');
        });

        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->string('reply_to_value')
                ->nullable()
                ->after('from_value');

            $table->string('contact_extraction_status', 32)
                ->nullable()
                ->after('inbound_email_route_context')
                ->index();
            $table->char('contact_extraction_definition_hash', 64)
                ->nullable()
                ->after('contact_extraction_status');
            $table->string('contact_extraction_error', 500)
                ->nullable()
                ->after('contact_extraction_definition_hash');
            $table->timestamp('contact_extraction_attempted_at')
                ->nullable()
                ->after('contact_extraction_error');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['contact_extraction_status']);
            $table->dropColumn([
                'reply_to_value',
                'contact_extraction_status',
                'contact_extraction_definition_hash',
                'contact_extraction_error',
                'contact_extraction_attempted_at',
            ]);
        });

        Schema::table('inbound_email_routes', function (Blueprint $table): void {
            $table->dropColumn([
                'contact_extraction_enabled',
                'contact_extraction_definition',
            ]);
        });
    }
};