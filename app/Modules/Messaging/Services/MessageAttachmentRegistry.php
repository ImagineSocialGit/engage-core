<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Support\MessageAttachmentReferences;
use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentUploadSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\SelectableMessageAttachmentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MessageAttachmentRegistry
{
    /** @var array<string, MessageAttachmentSource> */
    private array $sources = [];

    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            if (! $source instanceof MessageAttachmentSource) {
                throw new RuntimeException('Invalid message attachment source.');
            }
            $key = $source->key();
            if ($key === '' || isset($this->sources[$key])) {
                throw new RuntimeException("Duplicate or empty message attachment source [{$key}].");
            }
            $this->sources[$key] = $source;
        }
    }

    /** @return array<int, array{source: string, id: string, filename: string, size_bytes: int}> */
    public function selectable(?int $contactId = null): array
    {
        $options = [];
        $limit = max(1, (int) config('messaging.email.attachments.max_file_bytes', 10485760));

        foreach ($this->sources as $source) {
            if (! $source instanceof SelectableMessageAttachmentSource) {
                continue;
            }

            foreach ($source->selectable($contactId) as $file) {
                if (! $file instanceof AttachmentFile
                    || $file->source !== $source->key()
                    || $file->sizeBytes < 1
                    || $file->sizeBytes > $limit
                    || ($file->contactId !== null && $file->contactId !== $contactId)) {
                    continue;
                }

                $options[] = [
                    'source' => $file->source,
                    'id' => $file->id,
                    'filename' => $file->filename,
                    'size_bytes' => $file->sizeBytes,
                ];
            }
        }

        return $options;
    }

    /** @return array<int, array{key: string, label: string}> */
    public function uploadableSources(): array
    {
        $options = [];
        foreach ($this->sources as $source) {
            if ($source instanceof MessageAttachmentUploadSource) {
                $options[] = ['key' => $source->key(), 'label' => $source->uploadLabel()];
            }
        }

        return $options;
    }

    public function upload(
        string $sourceKey,
        UploadedFile $file,
        ?int $contactId = null,
        ?Model $uploadedBy = null,
    ): AttachmentFile {
        $source = $this->sources[$sourceKey] ?? null;
        if (! $source instanceof MessageAttachmentUploadSource) {
            throw new RuntimeException('That attachment upload destination is unavailable.');
        }

        $stored = $source->storeForMessage($file, $contactId, $uploadedBy);
        if ($stored->source !== $sourceKey || $stored->id === '') {
            throw new RuntimeException('The attachment source returned an invalid file reference.');
        }

        return $stored;
    }

    /** @return array<int, AttachmentFile> */
    public function resolve(mixed $references, ?int $contactId = null): array
    {
        $references = MessageAttachmentReferences::normalize($references);
        $totalBytes = 0;
        $files = [];
        $maxFileBytes = max(1, (int) config('messaging.email.attachments.max_file_bytes', 10485760));
        $maxTotalBytes = max(1, (int) config('messaging.email.attachments.max_total_bytes', 15728640));

        foreach ($references as $reference) {
            $source = $this->sources[$reference['source']] ?? null;
            if ($source === null) {
                throw new RuntimeException("Attachment source [{$reference['source']}] is unavailable.");
            }
            $file = $source->find($reference['id']);
            if ($file === null || $file->source !== $reference['source'] || $file->id !== $reference['id']) {
                throw new RuntimeException('An attachment is unavailable.');
            }
            if ($file->contactId !== null && $file->contactId !== $contactId) {
                throw new RuntimeException('An attachment belongs to another contact.');
            }
            if ($file->sizeBytes <= 0 || $file->sizeBytes > $maxFileBytes
                || $totalBytes + $file->sizeBytes > $maxTotalBytes) {
                throw new RuntimeException('Email attachments exceed the configured size limit.');
            }
            if ($file->disk === '' || $file->path === '' || $file->filename === '' || $file->mimeType === ''
                || ! Storage::disk($file->disk)->exists($file->path)) {
                throw new RuntimeException('An attachment file is missing.');
            }
            $totalBytes += $file->sizeBytes;
            $files[] = $file;
        }

        return $files;
    }
}