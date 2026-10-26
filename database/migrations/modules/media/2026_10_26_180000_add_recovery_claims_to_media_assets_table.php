<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->timestamp('image_variants_recovery_dispatched_at')->nullable();
            $table->timestamp('video_ingestion_recovery_dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn([
                'image_variants_recovery_dispatched_at',
                'video_ingestion_recovery_dispatched_at',
            ]);
        });
    }
};