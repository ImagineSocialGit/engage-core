<?php

namespace App\Support\ModuleIntegrations\Documents;

final readonly class DocumentAttachment
{
    public function __construct(
        public int $id,
        public ?int $contactId,
        public string $disk,
        public string $path,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
    ) {}
}