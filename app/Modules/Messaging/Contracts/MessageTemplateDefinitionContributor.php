<?php

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Data\MessageTemplateDefinitionContribution;

interface MessageTemplateDefinitionContributor
{
    public function moduleKey(): string;

    public function moduleLabel(): string;

    /** @return array<int, string> */
    public function ownedScopes(): array;

    /** @return iterable<MessageTemplateDefinitionContribution> */
    public function contributions(): iterable;
}