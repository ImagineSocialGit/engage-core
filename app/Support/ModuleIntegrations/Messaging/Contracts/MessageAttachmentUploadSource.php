<?php

namespace App\Support\ModuleIntegrations\Messaging\Contracts;

use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/** Optional authoring upload; delivery sources are never required to create files. */
interface MessageAttachmentUploadSource
{
    public function uploadLabel(): string;

    public function storeForMessage(
        UploadedFile $file,
        ?int $contactId = null,
        ?Model $uploadedBy = null,
    ): AttachmentFile;
}