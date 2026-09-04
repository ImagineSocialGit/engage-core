<?php

namespace App\Modules\Media\Console\Commands;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\MediaImageVariantGenerator;
use Illuminate\Console\Command;
use Throwable;

final class BackfillMediaImageVariantsCommand extends Command
{
    protected $signature = 'media:image-variants:backfill
        {--limit=0 : Maximum number of image assets to inspect; zero means no limit}
        {--force : Regenerate variants even when current version metadata is already present}';

    protected $description = 'Generate progressive WebP derivatives for existing Media image assets without changing original asset identity.';

    public function handle(MediaImageVariantGenerator $generator): int
    {
        if (! (bool) config('media.image_variants.enabled', true)) {
            $this->warn('Media image variants are disabled; nothing was generated.');

            return self::SUCCESS;
        }

        if (! $generator->available()) {
            $this->error('GD WebP image processing is unavailable; Media image variants cannot be generated.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $query = MediaAsset::query()
            ->where('kind', MediaAsset::KIND_IMAGE)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $processed = 0;
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($query->cursor() as $asset) {
            $processed++;

            if (! $force && $asset->hasProgressiveImageVariants()) {
                $skipped++;
                continue;
            }

            try {
                if ($generator->generate($asset)) {
                    $generated++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->error(
                    "Media asset [{$asset->getKey()}] variant generation failed: {$exception->getMessage()}",
                );
            }
        }

        $this->table(
            ['Inspected', 'Generated', 'Skipped', 'Failed'],
            [[$processed, $generated, $skipped, $failed]],
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}