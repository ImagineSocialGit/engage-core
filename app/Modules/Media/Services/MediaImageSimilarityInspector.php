<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Data\ImagePerceptualFingerprint;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class MediaImageSimilarityInspector
{
    public function __construct(
        private readonly MediaUploadPolicy $uploadPolicy,
        private readonly MediaFileIdentity $fileIdentity,
        private readonly ImagePerceptualHasher $perceptualHasher,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     algorithm: string|null,
     *     candidates: array<int, array<string, mixed>>
     * }
     */
    public function inspect(UploadedFile $file): array
    {
        $mimeType = $this->uploadPolicy->effectiveMimeType($file);

        if ($mimeType === null) {
            throw new RuntimeException('The uploaded media type is not supported.');
        }

        $checksum = $this->fileIdentity->checksum($file);
        $exact = MediaAsset::query()
            ->where('checksum_sha256', $checksum)
            ->first();

        if ($exact instanceof MediaAsset) {
            return [
                'status' => 'exact_duplicate',
                'algorithm' => null,
                'candidates' => [$this->candidate($exact, 0)],
            ];
        }

        if (! $this->enabled()
            || $this->uploadPolicy->kindForMimeType($mimeType) !== MediaAsset::KIND_IMAGE
        ) {
            return $this->emptyResult('not_applicable');
        }

        if (! $this->perceptualHasher->available()) {
            return $this->emptyResult('unavailable');
        }

        $fingerprint = $this->perceptualHasher->fingerprint($file);

        if (! $fingerprint instanceof ImagePerceptualFingerprint) {
            return $this->emptyResult('unavailable');
        }

        $matches = [];
        $maximumDistance = $this->maximumDistance();
        $maximumCandidates = $this->maximumCandidates();

        foreach (
            MediaAsset::query()
                ->active()
                ->where('kind', MediaAsset::KIND_IMAGE)
                ->where('perceptual_hash_algorithm', $fingerprint->algorithm)
                ->whereNotNull('perceptual_hash')
                ->orderBy('id')
                ->lazyById(250) as $asset
        ) {
            if (! $this->aspectRatioCompatible($fingerprint, $asset)) {
                continue;
            }

            $distance = $this->perceptualHasher->hammingDistance(
                $fingerprint->hash,
                (string) $asset->perceptual_hash,
            );

            if ($distance === null || $distance > $maximumDistance) {
                continue;
            }

            $matches[] = $this->candidate($asset, $distance);

            usort($matches, static function (array $left, array $right): int {
                return [
                    $left['distance'],
                    -$left['id'],
                ] <=> [
                    $right['distance'],
                    -$right['id'],
                ];
            });

            if (count($matches) > $maximumCandidates) {
                array_pop($matches);
            }
        }

        return [
            'status' => $matches === [] ? 'clear' : 'near_duplicate',
            'algorithm' => $fingerprint->algorithm,
            'candidates' => array_values($matches),
        ];
    }

    /** @return array{status: string, algorithm: string|null, candidates: array<int, array<string, mixed>>} */
    private function emptyResult(string $status): array
    {
        return [
            'status' => $status,
            'algorithm' => null,
            'candidates' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function candidate(MediaAsset $asset, int $distance): array
    {
        return [
            'id' => (int) $asset->getKey(),
            'uuid' => (string) $asset->uuid,
            'title' => (string) $asset->title,
            'distance' => $distance,
            'public_url' => $asset->publicUrl(),
            'image_width' => $asset->image_width,
            'image_height' => $asset->image_height,
            'archived' => $asset->archived_at !== null,
        ];
    }

    private function aspectRatioCompatible(
        ImagePerceptualFingerprint $fingerprint,
        MediaAsset $asset,
    ): bool {
        $width = (int) $asset->image_width;
        $height = (int) $asset->image_height;

        if ($width < 1 || $height < 1) {
            return true;
        }

        $uploadedRatio = $fingerprint->width / $fingerprint->height;
        $assetRatio = $width / $height;
        $largest = max($uploadedRatio, $assetRatio);

        if ($largest <= 0) {
            return false;
        }

        return abs($uploadedRatio - $assetRatio) / $largest
            <= $this->aspectRatioTolerance();
    }

    private function enabled(): bool
    {
        return (bool) config('media.near_duplicate_images.enabled', true);
    }

    private function maximumDistance(): int
    {
        return min(64, max(0, (int) config(
            'media.near_duplicate_images.max_hamming_distance',
            8,
        )));
    }

    private function maximumCandidates(): int
    {
        return min(10, max(1, (int) config(
            'media.near_duplicate_images.max_candidates',
            3,
        )));
    }

    private function aspectRatioTolerance(): float
    {
        return min(1.0, max(0.0, (float) config(
            'media.near_duplicate_images.aspect_ratio_tolerance',
            0.08,
        )));
    }
}