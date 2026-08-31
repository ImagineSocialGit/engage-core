<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->foreignId('automated_response_scheduled_message_id')
                ->nullable()
                ->after('correlated_scheduled_message_id')
                ->constrained('scheduled_messages')
                ->nullOnDelete();

            $table->timestamp('automated_handled_at')
                ->nullable()
                ->after('completed_at')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['automated_handled_at']);
            $table->dropConstrainedForeignId(
                'automated_response_scheduled_message_id',
            );
            $table->dropColumn('automated_handled_at');
        });
    }
};