<?php

namespace App\Modules\InternalNotifications\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;

final class InternalNotificationsDeploymentPlanContributor implements DeploymentPlanContributor
{
    public function owner(): string
    {
        return 'internal_notifications';
    }

    /** @return iterable<int, EnvironmentRequirement> */
    public function environmentRequirements(): iterable
    {
        yield $this->requiresExplicitLiveEmailSender()
            ? EnvironmentRequirement::required(
                'INTERNAL_NOTIFICATION_FROM_ADDRESS',
                'Live Internal Notifications email needs a resolved sender address. Set this override when the shared Messaging MAIL_FROM_ADDRESS fallback is not available.',
            )
            : EnvironmentRequirement::optional(
                'INTERNAL_NOTIFICATION_FROM_ADDRESS',
                'Optional Internal Notifications email sender override; when omitted the shared Messaging MAIL_FROM_ADDRESS fallback remains authoritative.',
            );

        yield EnvironmentRequirement::optional(
            'INTERNAL_NOTIFICATION_FROM_NAME',
            'Optional Internal Notifications email display-name override; when omitted Messaging falls back to MAIL_FROM_NAME and then the application name.',
        );
    }

    private function requiresExplicitLiveEmailSender(): bool
    {
        return $this->usesExternalTransport()
            && $this->internalEmailSurfaceEnabled()
            && ! $this->hasResolvedInternalEmailSender();
    }

    private function usesExternalTransport(): bool
    {
        return ! app()->environment(['local', 'testing']);
    }

    private function internalEmailSurfaceEnabled(): bool
    {
        return (bool) config(
            'messaging.channel_availability.email.surfaces.internal_notifications',
            false,
        );
    }

    private function hasResolvedInternalEmailSender(): bool
    {
        $address = config('messaging.internal_notifications.email.from_address');

        return is_string($address) && trim($address) !== '';
    }
}