<?php

namespace App\Support\ModuleIntegrations\Messaging\Attachments;

final readonly class AttachmentFile
{
    public function __construct(
        public string $source,
        public string $id,
        public string $disk,
        public string $path,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public ?int $contactId = null,
    ) {}
}