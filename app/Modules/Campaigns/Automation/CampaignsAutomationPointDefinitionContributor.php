<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Data\Automation\CancelCampaignAutomationDefinition;
use App\Modules\Campaigns\Data\Automation\CancelCampaignFamilyAutomationDefinition;
use App\Modules\Campaigns\Data\Automation\EnrollCampaignAutomationDefinition;
use App\Modules\Campaigns\Data\Automation\PauseCampaignAutomationDefinition;
use App\Modules\Campaigns\Data\Automation\PauseCampaignFamilyAutomationDefinition;
use App\Modules\Campaigns\Data\Automation\ResumeCampaignAutomationDefinition;
use App\Modules\Campaigns\Models\Campaign;
use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class CampaignsAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    public function definitions(): iterable
    {
        $open = $this->openSchema();

        yield new AutomationPointDefinition(
            pointType: 'enroll_campaign',
            schema: ConfigSchema::object([
                'campaign_key' => ConfigField::required(
                    ConfigSchema::string(),
                    referenceTarget: 'campaigns',
                ),
                'on_already_enrolled' => ConfigField::defaulted(
                    ConfigSchema::string(
                        allowedValues: EnrollCampaignAutomationDefinition::ON_ALREADY_ENROLLED_OPTIONS,
                    ),
                    EnrollCampaignAutomationDefinition::ON_ALREADY_ENROLLED_SKIPPED,
                ),
                'payload' => ConfigField::defaulted($open, []),
                'meta' => ConfigField::defaulted($open, []),
                'start_context' => ConfigField::optional(
                    ConfigSchema::oneOf([$open], nullable: true),
                ),
                'exit_conditions' => ConfigField::optional(
                    ConfigSchema::oneOf([$open], nullable: true),
                ),
            ]),
        );

        yield new AutomationPointDefinition(
            pointType: 'cancel_campaign',
            schema: $this->campaignLifecycleSchema(
                CancelCampaignAutomationDefinition::ON_NOT_ENROLLED_OPTIONS,
                'flow_route_cancelled_campaign',
                true,
            ),
        );

        yield new AutomationPointDefinition(
            pointType: 'pause_campaign',
            schema: $this->campaignLifecycleSchema(
                PauseCampaignAutomationDefinition::ON_NOT_ENROLLED_OPTIONS,
                'flow_route_paused_campaign',
                true,
            ),
        );

        yield new AutomationPointDefinition(
            pointType: 'resume_campaign',
            schema: $this->campaignLifecycleSchema(
                ResumeCampaignAutomationDefinition::ON_NOT_ENROLLED_OPTIONS,
                'flow_route_resumed_campaign',
                false,
            ),
        );

        yield new AutomationPointDefinition(
            pointType: 'pause_campaign_family',
            schema: $this->familyLifecycleSchema(
                PauseCampaignFamilyAutomationDefinition::ON_NOT_ENROLLED_OPTIONS,
                'flow_route_paused_campaign_family',
            ),
        );

        yield new AutomationPointDefinition(
            pointType: 'cancel_campaign_family',
            schema: $this->familyLifecycleSchema(
                CancelCampaignFamilyAutomationDefinition::ON_NOT_ENROLLED_OPTIONS,
                'flow_route_cancelled_campaign_family',
            ),
        );
    }

    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        $input = array_replace_recursive($definition, $settings);

        $parsed = match ($pointType) {
            'enroll_campaign' => EnrollCampaignAutomationDefinition::from($input),
            'cancel_campaign' => CancelCampaignAutomationDefinition::from($input),
            'pause_campaign' => PauseCampaignAutomationDefinition::from($input),
            'resume_campaign' => ResumeCampaignAutomationDefinition::from($input),
            'pause_campaign_family' => PauseCampaignFamilyAutomationDefinition::from($input),
            'cancel_campaign_family' => CancelCampaignFamilyAutomationDefinition::from($input),
            default => null,
        };

        if ($parsed === null) {
            return;
        }

        if (! $parsed->isValid()) {
            yield $context->error(
                code: 'flow_routes.point_definition_invalid',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] has invalid [{$pointType}] definition [{$parsed->invalidReason}].",
                path: "{$context->path}.definition",
                context: [
                    'point_key' => $context->pointKey,
                    'point_type' => $pointType,
                    'invalid_reason' => $parsed->invalidReason,
                ],
            );

            return;
        }

        if (property_exists($parsed, 'campaignKey')
            && $parsed->campaignKey !== null
            && ! Campaign::query()->where('key', $parsed->campaignKey)->exists()
        ) {
            yield $context->error(
                code: 'flow_routes.campaign_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references missing Campaign [{$parsed->campaignKey}].",
                path: "{$context->path}.definition.campaign_key",
                context: [
                    'point_key' => $context->pointKey,
                    'campaign_key' => $parsed->campaignKey,
                ],
            );
        }

        if (property_exists($parsed, 'familyKey')
            && $parsed->familyKey !== null
            && ! Campaign::query()->where('family_key', $parsed->familyKey)->exists()
        ) {
            yield $context->error(
                code: 'flow_routes.campaign_family_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references missing Campaign family [{$parsed->familyKey}].",
                path: "{$context->path}.definition.family_key",
                context: [
                    'point_key' => $context->pointKey,
                    'family_key' => $parsed->familyKey,
                ],
            );
        }
    }

    /**
     * @param array<int, string> $onNotEnrolledOptions
     */
    private function campaignLifecycleSchema(
        array $onNotEnrolledOptions,
        string $defaultReason,
        bool $withSkipPendingMessages,
    ): ConfigSchema {
        $fields = [
            'campaign_key' => ConfigField::required(
                ConfigSchema::string(),
                referenceTarget: 'campaigns',
            ),
            'reason' => ConfigField::defaulted(
                ConfigSchema::string(),
                $defaultReason,
            ),
            'on_not_enrolled' => ConfigField::defaulted(
                ConfigSchema::string(allowedValues: $onNotEnrolledOptions),
                'skipped',
            ),
            'meta' => ConfigField::defaulted($this->openSchema(), []),
        ];

        if ($withSkipPendingMessages) {
            $fields['skip_pending_messages'] = ConfigField::defaulted(
                ConfigSchema::boolean(),
                true,
            );
        }

        return ConfigSchema::object($fields);
    }

    /** @param array<int, string> $onNotEnrolledOptions */
    private function familyLifecycleSchema(
        array $onNotEnrolledOptions,
        string $defaultReason,
    ): ConfigSchema {
        return ConfigSchema::object([
            'family_key' => ConfigField::required(ConfigSchema::string()),
            'reason' => ConfigField::defaulted(
                ConfigSchema::string(),
                $defaultReason,
            ),
            'on_not_enrolled' => ConfigField::defaulted(
                ConfigSchema::string(allowedValues: $onNotEnrolledOptions),
                'skipped',
            ),
            'skip_pending_messages' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                true,
            ),
            'meta' => ConfigField::defaulted($this->openSchema(), []),
        ]);
    }

    private function openSchema(): ConfigSchema
    {
        return ConfigSchema::object([], allowUnknown: true);
    }
}