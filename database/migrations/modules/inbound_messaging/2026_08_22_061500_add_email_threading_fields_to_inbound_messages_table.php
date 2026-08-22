<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->string('message_id', 998)
                ->nullable()
                ->after('provider_context_id');
            $table->string('subject', 998)
                ->nullable()
                ->after('to_value');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->dropColumn([
                'message_id',
                'subject',
            ]);
        });
    }
};