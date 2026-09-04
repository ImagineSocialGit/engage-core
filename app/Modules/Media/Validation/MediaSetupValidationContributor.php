<?php

namespace App\Modules\Media\Validation;

use App\Modules\Media\Services\ImagePerceptualHasher;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

final class MediaSetupValidationContributor implements SetupValidationContributor
{
    public function __construct(
        private readonly ImagePerceptualHasher $hasher,
    ) {}

    /** @return iterable<int, SetupValidationFinding> */
    public function findings(): iterable
    {
        if (! (bool) config('media.near_duplicate_images.enabled', true)
            || $this->hasher->available()
        ) {
            return;
        }

        yield new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: 'media.image_similarity_gd_unavailable',
            message: 'Media near-duplicate image suggestions are enabled, but the PHP GD image extension is unavailable. Exact SHA-256 deduplication still works; install/enable GD to activate perceptual image comparison.',
            source: 'media.near_duplicate_images',
            path: 'media.near_duplicate_images.enabled',
            module: 'media',
        );
    }
}