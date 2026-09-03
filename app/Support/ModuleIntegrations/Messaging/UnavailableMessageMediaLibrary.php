<?php

namespace App\Support\ModuleIntegrations\Messaging;

use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class UnavailableMessageMediaLibrary implements MessageMediaLibrary
{
    public function available(): bool
    {
        return false;
    }

    public function selectableAssets(): array
    {
        return [];
    }

    public function snapshot(
        string $assetUuid,
        ?string $posterAssetUuid = null,
    ): array {
        throw new RuntimeException(
            'Message media is unavailable because the Media module is not enabled.',
        );
    }

    public function store(
        UploadedFile $file,
        ?string $title = null,
        ?string $posterAssetUuid = null,
        ?Model $uploadedBy = null,
    ): array {
        throw new RuntimeException(
            'Message media is unavailable because the Media module is not enabled.',
        );
    }
}