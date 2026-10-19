<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Actions\StoreGeneratedMediaSvgAction;
use App\Modules\Media\Contracts\ReusableMediaImageAuthoring;
use App\Modules\Media\Contracts\ReusableMediaImageLibrary;
use App\Modules\Media\Data\ReusableMediaImage;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaAssetAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseReusableMediaImageAuthoring implements ReusableMediaImageAuthoring
{
    public function __construct(
        private readonly StoreGeneratedMediaSvgAction $storeGeneratedSvg,
        private readonly ReusableMediaImageLibrary $images,
    ) {}

    public function storeGeneratedSvg(
        string $svg,
        string $title,
        string $source,
        ?Model $createdBy = null,
    ): ReusableMediaImage {
        $asset = $this->storeGeneratedSvg->handle(
            svg: $svg,
            title: $title,
            source: $source,
            createdBy: $createdBy,
        );

        $image = $this->images->find((string) $asset->uuid);

        if (! $image instanceof ReusableMediaImage) {
            throw new RuntimeException(
                'The generated Media SVG was stored but is not available through the reusable image library.',
            );
        }

        return $image;
    }

    public function assigned(
        Model $subject,
        string $role,
    ): ?ReusableMediaImage {
        [$subjectType, $subjectId] = $this->subjectIdentity($subject);
        $role = $this->normalizeRole($role);

        $assignment = MediaAssetAssignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('role', $role)
            ->first();

        if (! $assignment instanceof MediaAssetAssignment) {
            return null;
        }

        $asset = MediaAsset::query()->find($assignment->media_asset_id);

        return $asset instanceof MediaAsset
            ? $this->images->find((string) $asset->uuid)
            : null;
    }

    public function assign(
        Model $subject,
        string $role,
        string $assetUuid,
    ): ReusableMediaImage {
        [$subjectType, $subjectId] = $this->subjectIdentity($subject);
        $role = $this->normalizeRole($role);
        $assetUuid = trim($assetUuid);

        if ($assetUuid === '') {
            throw new RuntimeException('A Media image UUID is required for assignment.');
        }

        return DB::transaction(function () use (
            $subjectType,
            $subjectId,
            $role,
            $assetUuid,
        ): ReusableMediaImage {
            $asset = MediaAsset::query()
                ->active()
                ->ready()
                ->where('kind', MediaAsset::KIND_IMAGE)
                ->where('visibility', MediaAsset::VISIBILITY_PUBLIC)
                ->where('uuid', $assetUuid)
                ->first();

            if (! $asset instanceof MediaAsset) {
                throw new RuntimeException(
                    "Media image [{$assetUuid}] is not available for assignment.",
                );
            }

            MediaAssetAssignment::query()->updateOrCreate(
                [
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'role' => $role,
                ],
                [
                    'media_asset_id' => $asset->getKey(),
                ],
            );

            $image = $this->images->find((string) $asset->uuid);

            if (! $image instanceof ReusableMediaImage) {
                throw new RuntimeException(
                    "Assigned Media image [{$assetUuid}] is not available through the reusable image library.",
                );
            }

            return $image;
        });
    }

    public function clearAssignment(
        Model $subject,
        string $role,
    ): void {
        [$subjectType, $subjectId] = $this->subjectIdentity($subject);
        $role = $this->normalizeRole($role);

        MediaAssetAssignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('role', $role)
            ->delete();
    }

    /** @return array{0: string, 1: int} */
    private function subjectIdentity(Model $subject): array
    {
        if (! $subject->exists) {
            throw new RuntimeException('Media assignments require a persisted subject.');
        }

        $key = $subject->getKey();

        if (! is_int($key)
            && ! (is_string($key) && ctype_digit($key))
        ) {
            throw new RuntimeException(
                'Media assignments currently require an integer subject key.',
            );
        }

        $subjectType = trim((string) $subject->getMorphClass());

        if ($subjectType === '') {
            throw new RuntimeException('Media assignment subject type is unavailable.');
        }

        return [$subjectType, (int) $key];
    }

    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));

        if (preg_match('/\A[a-z][a-z0-9_.:-]{0,63}\z/', $role) !== 1) {
            throw new RuntimeException(
                'Media assignment role must be a stable lowercase key.',
            );
        }

        return $role;
    }
}