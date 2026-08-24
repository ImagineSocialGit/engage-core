<?php

namespace App\Modules\Messaging\Data;

use App\Modules\Messaging\Models\MessageTemplateVersion;

final readonly class MessageTemplatePresetPublicationResult
{
    public function __construct(
        public MessageTemplateVersion $version,
        public bool $overrideCleared,
    ) {}
}