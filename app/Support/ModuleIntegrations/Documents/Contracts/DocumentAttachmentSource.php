<?php

namespace App\Support\ModuleIntegrations\Documents\Contracts;

use App\Support\ModuleIntegrations\Documents\DocumentAttachment;

interface DocumentAttachmentSource
{
    public function find(int $documentUploadId): ?DocumentAttachment;
}