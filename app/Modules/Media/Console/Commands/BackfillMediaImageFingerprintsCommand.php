<?php

namespace App\Modules\Media\Console\Commands;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\ImagePerceptualHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class BackfillMediaImageFingerprintsCommand extends Command
{
    protected $signature = 'media:image-fingerprints:backfill
        {--limit=0 : Maximum number of image assets to inspect; zero means no limit}';

    protected $description = 'Generate perceptual fingerprints for existing Media image assets without changing asset identity or storage objects.';

    public function handle(ImagePerceptualHasher $hasher): int
    {
        if (! $hasher->available()) {
            $this->error('GD image decoding is unavailable; Media image fingerprints cannot be generated.');

            return self::FAILURE;
        }

        foreach ([
            'perceptual_hash',
            'perceptual_hash_algorithm',
            'image_width',
            'image_height',
        ] as $column) {
            if (! Schema::hasColumn('media_assets', $column)) {
                $this->error('Media image fingerprint columns are not installed. Run the Media module migrations first.');

                return self::FAILURE;
            }
        }

        $limit = max(0, (int) $this->option('limit'));
        $query = MediaAsset::query()
            ->where('kind', MediaAsset::KIND_IMAGE)
            ->where(function ($query): void {
                $query
                    ->whereNull('perceptual_hash')
                    ->orWhereNull('perceptual_hash_algorithm')
                    ->orWhere('perceptual_hash_algorithm', '<>', ImagePerceptualHasher::ALGORITHM);
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($query->cursor() as $asset) {
            $processed++;

            try {
                $bytes = Storage::disk((string) $asset->disk)->get((string) $asset->path);
                $fingerprint = is_string($bytes)
                    ? $hasher->fingerprintBytes($bytes)
                    : null;

                if ($fingerprint === null) {
                    $skipped++;
                    $this->warn("Media asset [{$asset->getKey()}] could not be fingerprinted; left unchanged.");

                    continue;
                }

                $asset->forceFill([
                    'perceptual_hash' => $fingerprint->hash,
                    'perceptual_hash_algorithm' => $fingerprint->algorithm,
                    'image_width' => $fingerprint->width,
                    'image_height' => $fingerprint->height,
                ])->save();

                $updated++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error(
                    "Media asset [{$asset->getKey()}] fingerprint failed: {$exception->getMessage()}",
                );
            }
        }

        $this->table(
            ['Inspected', 'Updated', 'Skipped', 'Failed'],
            [[$processed, $updated, $skipped, $failed]],
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}