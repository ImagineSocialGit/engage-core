<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_email_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 96)->unique();
            $table->string('local_part', 190)->unique();
            $table->string('label');
            $table->string('source', 96)->index();
            $table->string('context_key', 191)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(
                ['source', 'context_key', 'is_active'],
                'inbound_email_routes_source_context_active_idx',
            );
        });

        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->string('inbound_email_route_key', 96)
                ->nullable()
                ->after('reply_correlation_method')
                ->index();
            $table->string('inbound_email_route_source', 96)
                ->nullable()
                ->after('inbound_email_route_key');
            $table->string('inbound_email_route_context', 191)
                ->nullable()
                ->after('inbound_email_route_source');

            $table->index(
                ['inbound_email_route_source', 'inbound_email_route_context'],
                'inbound_messages_email_route_source_context_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->dropIndex('inbound_messages_email_route_source_context_idx');
            $table->dropIndex(['inbound_email_route_key']);
            $table->dropColumn([
                'inbound_email_route_key',
                'inbound_email_route_source',
                'inbound_email_route_context',
            ]);
        });

        Schema::dropIfExists('inbound_email_routes');
    }
};