<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateChecksum = DB::table('media_assets')
            ->select('checksum_sha256')
            ->whereNotNull('checksum_sha256')
            ->where('checksum_sha256', '<>', '')
            ->groupBy('checksum_sha256')
            ->havingRaw('COUNT(*) > 1')
            ->value('checksum_sha256');

        if (is_string($duplicateChecksum) && $duplicateChecksum !== '') {
            throw new \RuntimeException(
                'Media checksum uniqueness cannot be enabled while exact duplicate asset rows already exist. '
                .'Resolve the duplicate Media records before rerunning the Media migration.',
            );
        }

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex('media_assets_checksum_sha256_index');
            $table->unique(
                'checksum_sha256',
                'media_assets_checksum_sha256_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropUnique('media_assets_checksum_sha256_unique');
            $table->index(
                'checksum_sha256',
                'media_assets_checksum_sha256_index',
            );
        });
    }
};