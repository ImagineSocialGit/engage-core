<?php

namespace App\Modules\Media\Validation;

use App\Modules\Media\Services\ImagePerceptualHasher;
use App\Modules\Media\Services\MediaImageVariantGenerator;
use App\Modules\Media\Services\MediaVideoPosterGenerator;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

final class MediaSetupValidationContributor implements SetupValidationContributor
{
    public function __construct(
        private readonly ImagePerceptualHasher $hasher,
        private readonly ?MediaImageVariantGenerator $variantGenerator = null,
        private readonly ?MediaVideoPosterGenerator $videoPosterGenerator = null,
    ) {}

    /** @return iterable<int, SetupValidationFinding> */
    public function findings(): iterable
    {
        if ((bool) config('media.near_duplicate_images.enabled', true)
            && ! $this->hasher->available()
        ) {
            yield new SetupValidationFinding(
                severity: SetupValidationFinding::SEVERITY_WARNING,
                code: 'media.image_similarity_gd_unavailable',
                message: 'Media near-duplicate image suggestions are enabled, but the PHP GD image extension is unavailable. Exact SHA-256 deduplication still works; install/enable GD to activate perceptual image comparison.',
                source: 'media.near_duplicate_images',
                path: 'media.near_duplicate_images.enabled',
                module: 'media',
            );
        }

        if ((bool) config('media.image_variants.enabled', true)
            && $this->variantGenerator instanceof MediaImageVariantGenerator
            && ! $this->variantGenerator->available()
        ) {
            yield new SetupValidationFinding(
                severity: SetupValidationFinding::SEVERITY_WARNING,
                code: 'media.image_variants_gd_webp_unavailable',
                message: 'Media progressive image variants are enabled, but GD WebP encoding is unavailable. Original image uploads remain usable; install/enable GD WebP support to generate optimized Media derivatives.',
                source: 'media.image_variants',
                path: 'media.image_variants.enabled',
                module: 'media',
            );
        }

        if ((bool) config('media.video_posters.enabled', true)
            && $this->videoPosterGenerator instanceof MediaVideoPosterGenerator
            && ! $this->videoPosterGenerator->available()
        ) {
            yield new SetupValidationFinding(
                severity: SetupValidationFinding::SEVERITY_WARNING,
                code: 'media.video_posters.ffmpeg_unavailable',
                message: 'Video poster generation is enabled, but FFmpeg is unavailable. Video emails use the accessible video-card fallback until FFmpeg is installed.',
                source: 'media.video_posters',
                path: 'media.video_posters.ffmpeg_binary',
                module: 'media',
            );
        }
    }
}