<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->nullableMorphs('uploaded_by');
            $table->string('title');
            $table->string('kind', 32)->index();
            $table->string('disk', 64)->index();
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable()->index();
            $table->string('extension', 32)->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->char('checksum_sha256', 64)->nullable();
            $table->char('perceptual_hash', 16)->nullable();
            $table->string('perceptual_hash_algorithm', 32)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();

            $table->string('visibility', 32)->default('public')->index();
            $table->string('source', 64)->default('crm')->index();
            $table->json('meta')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['disk', 'path'],
                'media_assets_disk_path_unique',
            );
            $table->unique(
                'checksum_sha256',
                'media_assets_checksum_sha256_unique',
            );
            $table->index(
                ['kind', 'archived_at'],
                'media_assets_kind_archived_index',
            );
            $table->index(
                ['kind', 'perceptual_hash_algorithm', 'archived_at'],
                'media_assets_similarity_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};