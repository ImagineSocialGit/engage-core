<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->timestamp('pending_recovery_claimed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->dropColumn('pending_recovery_claimed_at');
        });
    }
};