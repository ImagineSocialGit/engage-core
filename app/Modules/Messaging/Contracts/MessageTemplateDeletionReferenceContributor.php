<?php

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;

interface MessageTemplateDeletionReferenceContributor
{
    public const TAG = 'messaging.message_template_deletion_reference_contributors';

    /**
     * @return iterable<int, \App\Modules\Messaging\Data\MessageTemplateDeletionReference>
     */
    public function references(
        MessageTemplatePreset $preset,
        ?MessageTemplate $template,
    ): iterable;
}