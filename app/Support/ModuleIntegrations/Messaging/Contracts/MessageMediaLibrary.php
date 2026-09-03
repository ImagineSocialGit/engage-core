<?php

namespace App\Support\ModuleIntegrations\Messaging\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

interface MessageMediaLibrary
{
    public function available(): bool;

    /**
     * @return array<int, array{
     *     uuid: string,
     *     title: string,
     *     kind: string,
     *     mime_type: ?string,
     *     public_url: string
     * }>
     */
    public function selectableAssets(): array;

    /** @return array<string, mixed> */
    public function snapshot(
        string $assetUuid,
        ?string $posterAssetUuid = null,
    ): array;

    /** @return array<string, mixed> */
    public function store(
        UploadedFile $file,
        ?string $title = null,
        ?string $posterAssetUuid = null,
        ?Model $uploadedBy = null,
    ): array;
}