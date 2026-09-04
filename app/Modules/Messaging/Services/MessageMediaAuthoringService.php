<?php

namespace App\Modules\Messaging\Services;

use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;

final class MessageMediaAuthoringService
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $selectableAssets = null;

    private ?bool $available = null;

    public function __construct(
        private readonly MessageMediaLibrary $messageMediaLibrary,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $currentSnapshots
     * @return array<string, mixed>
     */
    public function presentation(array $currentSnapshots = []): array
    {
        if (! $this->available()) {
            return [
                'available' => false,
                'assets' => [],
                'image_assets' => [],
                'asset_uuids' => [],
                'image_asset_uuids' => [],
                'library_url' => null,
            ];
        }

        $assets = array_values(array_filter(
            $this->selectableAssets(),
            static fn (mixed $asset): bool => is_array($asset)
                && is_string($asset['uuid'] ?? null)
                && trim($asset['uuid']) !== '',
        ));

        $known = [];

        foreach ($assets as $asset) {
            $known[(string) $asset['uuid']] = true;
        }

        foreach ($currentSnapshots as $snapshot) {
            if (! is_array($snapshot) || array_is_list($snapshot)) {
                continue;
            }

            $uuid = is_string($snapshot['asset_uuid'] ?? null)
                ? trim($snapshot['asset_uuid'])
                : '';

            if ($uuid !== '' && ! isset($known[$uuid])) {
                $assets[] = [
                    'uuid' => $uuid,
                    'title' => is_string($snapshot['title'] ?? null) && trim($snapshot['title']) !== ''
                        ? trim($snapshot['title'])
                        : $uuid,
                    'kind' => is_string($snapshot['kind'] ?? null) && trim($snapshot['kind']) !== ''
                        ? trim($snapshot['kind'])
                        : 'media',
                    'mime_type' => is_string($snapshot['mime_type'] ?? null)
                        ? trim($snapshot['mime_type'])
                        : null,
                    'public_url' => is_string($snapshot['url'] ?? null)
                        ? trim($snapshot['url'])
                        : '',
                    'archived' => true,
                ];
                $known[$uuid] = true;
            }

            $posterUuid = is_string($snapshot['poster_asset_uuid'] ?? null)
                ? trim($snapshot['poster_asset_uuid'])
                : '';

            if ($posterUuid !== '' && ! isset($known[$posterUuid])) {
                $assets[] = [
                    'uuid' => $posterUuid,
                    'title' => 'Current video poster',
                    'kind' => 'image',
                    'mime_type' => null,
                    'public_url' => is_string($snapshot['poster_url'] ?? null)
                        ? trim($snapshot['poster_url'])
                        : '',
                    'archived' => true,
                ];
                $known[$posterUuid] = true;
            }
        }

        $imageAssets = array_values(array_filter(
            $assets,
            static fn (array $asset): bool => ($asset['kind'] ?? null) === 'image',
        ));

        return [
            'available' => true,
            'assets' => $assets,
            'image_assets' => $imageAssets,
            'asset_uuids' => array_values(array_map(
                static fn (array $asset): string => (string) $asset['uuid'],
                $assets,
            )),
            'image_asset_uuids' => array_values(array_map(
                static fn (array $asset): string => (string) $asset['uuid'],
                $imageAssets,
            )),
            'library_url' => Route::has('crm.media.index')
                ? route('crm.media.index')
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function validationRules(string $prefix = ''): array
    {
        $key = static fn (string $field): string => $prefix === ''
            ? $field
            : $prefix.'.'.$field;

        return [
            $key('media_present') => ['nullable', 'boolean'],
            $key('media_asset_uuid') => ['nullable', 'uuid'],
            $key('media_poster_asset_uuid') => ['nullable', 'uuid'],
            $key('media_title') => ['nullable', 'string', 'max:255'],
            $key('media_upload') => [
                'nullable',
                'file',
                'max:'.max(1, (int) config('media.max_upload_kilobytes', 262144)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $currentMedia
     * @return array<string, mixed>
     */
    public function apply(
        array $payload,
        bool $submitted,
        ?UploadedFile $upload = null,
        ?string $assetUuid = null,
        ?string $posterAssetUuid = null,
        ?string $title = null,
        array $currentMedia = [],
        ?Model $uploadedBy = null,
    ): array {
        if (! $submitted) {
            if ($currentMedia !== []) {
                $payload['media'] = $currentMedia;
            }

            return $payload;
        }

        $media = $this->resolve(
            submitted: true,
            upload: $upload,
            assetUuid: $assetUuid,
            posterAssetUuid: $posterAssetUuid,
            title: $title,
            currentMedia: $currentMedia,
            uploadedBy: $uploadedBy,
        );

        if ($media === null) {
            unset($payload['media']);

            return $payload;
        }

        $payload['media'] = $media;

        return $payload;
    }

    /**
     * @param array<string, mixed> $currentMedia
     * @return array<string, mixed>|null
     */
    public function resolve(
        bool $submitted,
        ?UploadedFile $upload = null,
        ?string $assetUuid = null,
        ?string $posterAssetUuid = null,
        ?string $title = null,
        array $currentMedia = [],
        ?Model $uploadedBy = null,
    ): ?array {
        if (! $submitted) {
            return $currentMedia !== [] ? $currentMedia : null;
        }

        if (! $this->available()) {
            throw new InvalidArgumentException(
                'Enable the Media module before adding media to a message.',
            );
        }

        $assetUuid = is_string($assetUuid) ? trim($assetUuid) : '';
        $posterAssetUuid = is_string($posterAssetUuid) ? trim($posterAssetUuid) : '';
        $title = is_string($title) && trim($title) !== '' ? trim($title) : null;

        try {
            if ($upload instanceof UploadedFile) {
                return $this->messageMediaLibrary->store(
                    file: $upload,
                    title: $title,
                    posterAssetUuid: $posterAssetUuid !== '' ? $posterAssetUuid : null,
                    uploadedBy: $uploadedBy,
                );
            }

            if ($assetUuid === '') {
                return null;
            }

            $currentAssetUuid = is_string($currentMedia['asset_uuid'] ?? null)
                ? trim($currentMedia['asset_uuid'])
                : '';
            $currentPosterUuid = is_string($currentMedia['poster_asset_uuid'] ?? null)
                ? trim($currentMedia['poster_asset_uuid'])
                : '';

            if ($assetUuid === $currentAssetUuid
                && $posterAssetUuid === $currentPosterUuid
                && $currentMedia !== []
            ) {
                return $currentMedia;
            }

            return $this->messageMediaLibrary->snapshot(
                assetUuid: $assetUuid,
                posterAssetUuid: $posterAssetUuid !== '' ? $posterAssetUuid : null,
            );
        } catch (Throwable $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }
    }

    private function available(): bool
    {
        return $this->available ??= $this->messageMediaLibrary->available();
    }

    /** @return array<int, array<string, mixed>> */
    private function selectableAssets(): array
    {
        if ($this->selectableAssets === null) {
            $this->selectableAssets = $this->messageMediaLibrary->selectableAssets();
        }

        return $this->selectableAssets;
    }

}