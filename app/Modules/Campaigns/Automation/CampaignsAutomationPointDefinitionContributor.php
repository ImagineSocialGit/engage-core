<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Data\Automation\CancelCampaignAutomationDefinition;
use App\Modules\Campaigns\Data\Automation\EnrollCampaignAutomationDefinition;
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
            schema: ConfigSchema::object([
                'campaign_key' => ConfigField::required(
                    ConfigSchema::string(),
                    referenceTarget: 'campaigns',
                ),
                'reason' => ConfigField::defaulted(
                    ConfigSchema::string(),
                    'flow_route_cancelled_campaign',
                ),
                'on_not_enrolled' => ConfigField::defaulted(
                    ConfigSchema::string(
                        allowedValues: CancelCampaignAutomationDefinition::ON_NOT_ENROLLED_OPTIONS,
                    ),
                    CancelCampaignAutomationDefinition::ON_NOT_ENROLLED_SKIPPED,
                ),
                'skip_pending_messages' => ConfigField::defaulted(ConfigSchema::boolean(), true),
                'meta' => ConfigField::defaulted($open, []),
            ]),
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

        if ($parsed->campaignKey !== null
            && ! Campaign::query()
                ->where('key', $parsed->campaignKey)
                ->exists()
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
    }

    private function openSchema(): ConfigSchema
    {
        return ConfigSchema::object([], allowUnknown: true);
    }
}