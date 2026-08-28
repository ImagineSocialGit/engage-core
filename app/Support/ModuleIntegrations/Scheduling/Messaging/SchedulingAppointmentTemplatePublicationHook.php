<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Models\User;
use App\Modules\Messaging\Contracts\MessageTemplatePublicationHook;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;

final class SchedulingAppointmentTemplatePublicationHook implements MessageTemplatePublicationHook
{
    public function __construct(
        private readonly MessagingAppointmentCommunications $appointmentCommunications,
    ) {}

    public function afterPublish(
        MessageTemplatePreset $preset,
        MessageTemplateVersion $version,
        ?User $actor = null,
    ): void {
        $this->appointmentCommunications->refreshChainForPublishedTemplate(
            preset: $preset,
            version: $version,
            actor: $actor,
        );
    }
}