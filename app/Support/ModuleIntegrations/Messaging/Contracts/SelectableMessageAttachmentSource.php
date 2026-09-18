<?php

namespace App\Support\ModuleIntegrations\Messaging\Contracts;

use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;

/** Optional authoring catalog; delivery sources only need MessageAttachmentSource. */
interface SelectableMessageAttachmentSource
{
    /** @return array<int, AttachmentFile> */
    public function selectable(?int $contactId = null): array;
}