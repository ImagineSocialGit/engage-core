<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->char('perceptual_hash', 16)
                ->nullable()
                ->after('checksum_sha256');
            $table->string('perceptual_hash_algorithm', 32)
                ->nullable()
                ->after('perceptual_hash');
            $table->unsignedInteger('image_width')
                ->nullable()
                ->after('perceptual_hash_algorithm');
            $table->unsignedInteger('image_height')
                ->nullable()
                ->after('image_width');

            $table->index(
                ['kind', 'perceptual_hash_algorithm', 'archived_at'],
                'media_assets_similarity_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex('media_assets_similarity_lookup_index');
            $table->dropColumn([
                'perceptual_hash',
                'perceptual_hash_algorithm',
                'image_width',
                'image_height',
            ]);
        });
    }
};