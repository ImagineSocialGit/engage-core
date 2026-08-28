<?php

namespace App\Modules\Messaging\Contracts;

use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;

interface MessageTemplatePublicationHook
{
    public function afterPublish(
        MessageTemplatePreset $preset,
        MessageTemplateVersion $version,
        ?User $actor = null,
    ): void;
}