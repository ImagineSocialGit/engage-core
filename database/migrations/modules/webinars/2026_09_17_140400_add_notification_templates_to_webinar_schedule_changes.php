<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinar_schedule_changes', function (Blueprint $table): void {
            $table->string('notification_mode', 16)->default('manual');
            $table->json('template_version_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webinar_schedule_changes', function (Blueprint $table): void {
            $table->dropColumn(['notification_mode', 'template_version_ids']);
        });
    }
};