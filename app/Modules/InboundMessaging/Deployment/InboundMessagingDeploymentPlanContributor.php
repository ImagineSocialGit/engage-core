<?php

namespace App\Modules\InboundMessaging\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Modules\ModuleManager;

final class InboundMessagingDeploymentPlanContributor implements DeploymentPlanContributor
{
    public function __construct(
        private readonly ModuleManager $modules,
    ) {}

    public function owner(): string
    {
        return 'inbound_messaging';
    }

    /** @return iterable<int, EnvironmentRequirement> */
    public function environmentRequirements(): iterable
    {
        yield $this->usesExternalTransport()
            ? EnvironmentRequirement::required(
                'INBOUND_EMAIL_DOMAIN',
                'Live Inbound Messaging requires a verified receiving domain for signed per-message Reply-To correlation and semantic inbound email routes.',
                valueRule: EnvironmentRequirement::VALUE_RULE_EMAIL_DOMAIN,
            )
            : EnvironmentRequirement::optional(
                'INBOUND_EMAIL_DOMAIN',
                'Local/testing may omit the live inbound email receiving domain; when persisted it must still be a valid bare email domain.',
                valueRule: EnvironmentRequirement::VALUE_RULE_EMAIL_DOMAIN,
            );

        if ($this->moduleEnabled('internal_notifications')) {
            yield EnvironmentRequirement::optional(
                'INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL',
                'Optional fallback recipient for inbound-reply Internal Notifications when no assigned active team member resolves.',
            );
        }
    }

    private function usesExternalTransport(): bool
    {
        return ! app()->environment(['local', 'testing']);
    }

    private function moduleEnabled(string $module): bool
    {
        return in_array(
            $module,
            $this->modules->enabledKeysWithDependencies(),
            true,
        );
    }
}