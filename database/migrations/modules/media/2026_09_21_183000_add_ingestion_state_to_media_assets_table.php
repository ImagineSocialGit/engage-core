<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('ingestion_status', 32)
                ->default('ready')
                ->index()
                ->after('size_bytes');
            $table->text('ingestion_error')
                ->nullable()
                ->after('ingestion_status');
            $table->timestamp('ingested_at')
                ->nullable()
                ->after('ingestion_error');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex(['ingestion_status']);
            $table->dropColumn([
                'ingestion_status',
                'ingestion_error',
                'ingested_at',
            ]);
        });
    }
};