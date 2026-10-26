<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_import_batches', function (Blueprint $table): void {
            // NULL is intentional: preexisting completed imports are not
            // silently replayed as new automation triggers on deployment.
            $table->unsignedBigInteger('automation_events_cursor')->nullable();
            $table->timestamp('automation_events_completed_at')->nullable();
            $table->timestamp('automation_events_recovery_dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_import_batches', function (Blueprint $table): void {
            $table->dropColumn([
                'automation_events_cursor',
                'automation_events_completed_at',
                'automation_events_recovery_dispatched_at',
            ]);
        });
    }
};