<?php

namespace App\Modules\Webinars\MessageTemplates;

use App\Modules\Messaging\Contracts\MessageTemplateDefinitionContributor;
use App\Modules\Messaging\Data\MessageTemplateDefinitionContribution;
use App\Modules\Messaging\Services\MessageDefinitionConfigSetResolver;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;

final class WebinarMessageTemplateDefinitionContributor implements MessageTemplateDefinitionContributor
{
    private const OWNED_SCOPES = [
        'webinar',
        'webinar_waitlist',
        'webinar_nurture',
    ];

    public function __construct(
        private readonly MessageDefinitionConfigSetResolver $configSetResolver,
    ) {}

    public function moduleKey(): string
    {
        return 'webinars';
    }

    public function moduleLabel(): string
    {
        return 'Webinars';
    }

    public function ownedScopes(): array
    {
        return self::OWNED_SCOPES;
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

                foreach (self::OWNED_SCOPES as $scope) {
                    $scopeConfig = $purposeConfig[$scope] ?? null;

                    if (! is_array($scopeConfig) || $scopeConfig === []) {
                        continue;
                    }

                    $definitions = $this->standardDefinitions($scope, $scopeConfig);

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
                        surface: match ($scope) {
                            'webinar' => 'webinar_registrations',
                            'webinar_waitlist' => 'webinar_waitlists',
                            default => null,
                        },
                    );
                }
            }
        }
    }

    /**
     * Keep Campaign-owned campaign-step definitions out of the Webinar
     * contribution while preserving any named Webinar template-set structure.
     *
     * @param array<string, mixed> $scopeConfig
     * @return array<string, mixed>
     */
    private function standardDefinitions(string $scope, array $scopeConfig): array
    {
        $resolved = [];

        foreach ($this->configSetResolver->sets($scope, $scopeConfig) as $set) {
            $definitions = $set['definitions'];
            unset($definitions['campaigns']);

            if ($definitions === []) {
                continue;
            }

            if ($set['key'] === null) {
                return $definitions;
            }

            $setKey = $set['source_key'] ?? $set['key'];

            if (is_string($setKey) && trim($setKey) !== '') {
                $resolved[$setKey] = $definitions;
            }
        }

        return $resolved;
    }
}