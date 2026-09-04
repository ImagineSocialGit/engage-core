<?php

namespace Database\Factories;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\ImagePerceptualHasher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'uploaded_by_type' => null,
            'uploaded_by_id' => null,
            'title' => 'Media asset',
            'kind' => MediaAsset::KIND_IMAGE,
            'disk' => 'public',
            'path' => "media/{$uuid}/{$uuid}.jpg",
            'original_filename' => 'media-asset.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $uuid),
            'perceptual_hash' => substr(hash('sha256', 'perceptual:'.$uuid), 0, 16),
            'perceptual_hash_algorithm' => ImagePerceptualHasher::ALGORITHM,
            'image_width' => 1200,
            'image_height' => 800,
            'visibility' => MediaAsset::VISIBILITY_PUBLIC,
            'source' => 'crm',
            'meta' => null,
            'archived_at' => null,
        ];
    }

    public function video(): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => MediaAsset::KIND_VIDEO,
            'path' => preg_replace('/\.jpg$/', '.mp4', (string) $attributes['path']),
            'original_filename' => 'greeting.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'perceptual_hash' => null,
            'perceptual_hash_algorithm' => null,
            'image_width' => null,
            'image_height' => null,
        ]);
    }

    public function archived(): self
    {
        return $this->state([
            'archived_at' => now(),
        ]);
    }
}