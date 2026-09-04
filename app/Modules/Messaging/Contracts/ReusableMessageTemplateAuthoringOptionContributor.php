<?php

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringOption;

interface ReusableMessageTemplateAuthoringOptionContributor
{
    public const TAG = 'messaging.reusable_message_template_authoring_options';

    /** @return iterable<int, ReusableMessageTemplateAuthoringOption> */
    public function options(): iterable;
}