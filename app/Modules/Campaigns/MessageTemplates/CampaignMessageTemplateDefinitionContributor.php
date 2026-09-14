<?php

namespace App\Modules\Campaigns\MessageTemplates;

use App\Modules\Messaging\Contracts\MessageTemplateDefinitionContributor;
use App\Modules\Messaging\Data\MessageTemplateDefinitionContribution;
use App\Modules\Messaging\Services\MessageDefinitionConfigSetResolver;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;

final class CampaignMessageTemplateDefinitionContributor implements MessageTemplateDefinitionContributor
{
    public function __construct(
        private readonly MessageDefinitionConfigSetResolver $configSetResolver,
    ) {}

    public function moduleKey(): string
    {
        return 'campaigns';
    }

    public function moduleLabel(): string
    {
        return 'Campaigns';
    }

    public function ownedScopes(): array
    {
        return [];
    }

    public function contributions(): iterable
    {
        foreach (['email', 'sms'] as $channel) {
            foreach (['transactional', 'marketing', 'internal'] as $purpose) {
                $purposeConfig = config(
                    MessageDefinitionConfigPath::purpose($channel, $purpose),
                    [],
                );

                if (! is_array($purposeConfig)) {
                    continue;
                }

                foreach ($purposeConfig as $scope => $scopeConfig) {
                    if (! is_string($scope)
                        || trim($scope) === ''
                        || ! is_array($scopeConfig)
                    ) {
                        continue;
                    }

                    $definitions = $this->campaignDefinitions($scope, $scopeConfig);

                    if ($definitions === []) {
                        continue;
                    }

                    yield new MessageTemplateDefinitionContribution(
                        channel: $channel,
                        purpose: $purpose,
                        scope: $scope,
                        definitions: $definitions,
                        sourceConfigPath: MessageDefinitionConfigPath::scope(
                            $channel,
                            $purpose,
                            $scope,
                        ),
                        surface: 'campaigns',
                    );
                }
            }
        }
    }

    /**
     * Extract only Campaign-owned campaign-step definitions while preserving
     * any named Webinar template-set wrapper used by Messaging config.
     *
     * @param array<string, mixed> $scopeConfig
     * @return array<string, mixed>
     */
    private function campaignDefinitions(string $scope, array $scopeConfig): array
    {
        $resolved = [];

        foreach ($this->configSetResolver->sets($scope, $scopeConfig) as $set) {
            $campaigns = $set['definitions']['campaigns'] ?? null;

            if (! is_array($campaigns) || $campaigns === []) {
                continue;
            }

            if ($set['key'] === null) {
                return ['campaigns' => $campaigns];
            }

            $setKey = $set['source_key'] ?? $set['key'];

            if (is_string($setKey) && trim($setKey) !== '') {
                $resolved[$setKey] = ['campaigns' => $campaigns];
            }
        }

        return $resolved;
    }
}