<?php

namespace App\Support\ModuleIntegrations\Messaging\Media;

use App\Modules\Media\Actions\StoreMediaAssetAction;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Messaging\Support\MessageMediaPayload;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class MediaMessageMediaLibrary implements MessageMediaLibrary
{
    public function __construct(
        private readonly StoreMediaAssetAction $storeMediaAsset,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function selectableAssets(): array
    {
        return MediaAsset::query()
            ->active()
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(function (MediaAsset $asset): ?array {
                $url = $asset->publicUrl();

                if (! is_string($url) || trim($url) === '') {
                    return null;
                }

                return [
                    'uuid' => (string) $asset->uuid,
                    'title' => (string) $asset->title,
                    'kind' => (string) $asset->kind,
                    'mime_type' => is_string($asset->mime_type) ? $asset->mime_type : null,
                    'public_url' => trim($url),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function snapshot(
        string $assetUuid,
        ?string $posterAssetUuid = null,
    ): array {
        $asset = $this->activeAsset($assetUuid, 'Selected media');

        return $this->snapshotForAsset($asset, $posterAssetUuid);
    }

    public function store(
        UploadedFile $file,
        ?string $title = null,
        ?string $posterAssetUuid = null,
        ?Model $uploadedBy = null,
    ): array {
        $asset = $this->storeMediaAsset->handle(
            file: $file,
            title: $title,
            uploadedBy: $uploadedBy,
        );

        return $this->snapshotForAsset($asset, $posterAssetUuid);
    }

    /** @return array<string, mixed> */
    private function snapshotForAsset(
        MediaAsset $asset,
        ?string $posterAssetUuid,
    ): array {
        $url = $asset->publicUrl();

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException(
                "Media asset [{$asset->uuid}] does not have a public URL.",
            );
        }

        $snapshot = [
            'asset_uuid' => (string) $asset->uuid,
            'kind' => (string) $asset->kind,
            'title' => (string) $asset->title,
            'url' => trim($url),
            'mime_type' => is_string($asset->mime_type) && trim($asset->mime_type) !== ''
                ? trim($asset->mime_type)
                : null,
            'tracking_key' => MessageMediaPayload::TRACKING_KEY,
        ];

        $posterAssetUuid = is_string($posterAssetUuid)
            ? trim($posterAssetUuid)
            : '';

        if ($posterAssetUuid !== '') {
            if ($asset->kind !== MediaAsset::KIND_VIDEO) {
                throw new RuntimeException(
                    'A poster image may only be selected for video media.',
                );
            }

            $poster = $this->activeAsset($posterAssetUuid, 'Selected poster');

            if ($poster->kind !== MediaAsset::KIND_IMAGE) {
                throw new RuntimeException('The selected video poster must be an image.');
            }

            $posterUrl = $poster->publicUrl();

            if (! is_string($posterUrl) || trim($posterUrl) === '') {
                throw new RuntimeException(
                    "Media poster [{$poster->uuid}] does not have a public URL.",
                );
            }

            $snapshot['poster_asset_uuid'] = (string) $poster->uuid;
            $snapshot['poster_url'] = trim($posterUrl);
        }

        $snapshot = array_filter(
            $snapshot,
            static fn (mixed $value): bool => $value !== null,
        );

        MessageMediaPayload::assertValid($snapshot);

        return $snapshot;
    }

    private function activeAsset(string $uuid, string $label): MediaAsset
    {
        $uuid = trim($uuid);

        $asset = $uuid !== ''
            ? MediaAsset::query()->active()->where('uuid', $uuid)->first()
            : null;

        if (! $asset instanceof MediaAsset) {
            throw new RuntimeException("{$label} is unavailable or archived.");
        }

        return $asset;
    }
}