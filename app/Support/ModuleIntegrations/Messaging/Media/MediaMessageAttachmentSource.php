<?php

namespace App\Support\ModuleIntegrations\Messaging\Media;

use App\Modules\Media\Models\MediaAsset;
use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\SelectableMessageAttachmentSource;

final class MediaMessageAttachmentSource implements MessageAttachmentSource, SelectableMessageAttachmentSource
{
    public function key(): string
    {
        return 'media';
    }

    public function find(string $id): ?AttachmentFile
    {
        $asset = MediaAsset::query()->where('uuid', $id)->first();
        if ($asset === null || $asset->visibility !== MediaAsset::VISIBILITY_PUBLIC
            || ! is_string($asset->disk) || ! is_string($asset->path)
            || ! is_string($asset->mime_type) || ! is_int($asset->size_bytes)) {
            return null;
        }

        return new AttachmentFile(
            source: $this->key(),
            id: $id,
            disk: $asset->disk,
            path: $asset->path,
            filename: basename((string) ($asset->original_filename ?: $asset->title)),
            mimeType: $asset->mime_type,
            sizeBytes: $asset->size_bytes,
        );
    }

    public function selectable(?int $contactId = null): array
    {
        return MediaAsset::query()
            ->active()
            ->where('visibility', MediaAsset::VISIBILITY_PUBLIC)
            ->whereNotNull('size_bytes')
            ->where('size_bytes', '<=', max(1, (int) config('messaging.email.attachments.max_file_bytes', 10485760)))
            ->latest('id')
            ->limit(75)
            ->get()
            ->map(fn (MediaAsset $asset): ?AttachmentFile => $this->find((string) $asset->uuid))
            ->filter()
            ->values()
            ->all();
    }
}