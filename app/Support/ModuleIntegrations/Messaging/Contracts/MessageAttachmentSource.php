<?php

namespace App\Support\ModuleIntegrations\Messaging\Contracts;

use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;

interface MessageAttachmentSource
{
    public const TAG = 'messaging.attachment_sources';

    public function key(): string;

    /** Resolve an already stored, stable file reference. This method must not generate files. */
    public function find(string $id): ?AttachmentFile;
}