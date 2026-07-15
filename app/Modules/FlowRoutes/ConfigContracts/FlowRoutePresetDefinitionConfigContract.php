<?php

namespace App\Modules\FlowRoutes\ConfigContracts;

use App\Modules\FlowRoutes\Data\Points\BranchEvaluatePointDefinition;
use App\Modules\FlowRoutes\Data\Points\CancelCampaignPointDefinition;
use App\Modules\FlowRoutes\Data\Points\ConditionPointDefinition;
use App\Modules\FlowRoutes\Data\Points\EnrollCampaignPointDefinition;
use App\Modules\Tasks\Models\Task;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class FlowRoutePresetDefinitionConfigContract implements ConfigContract
{
    private const OUTCOMES = [
        'completed',
        'skipped',
        'blocked',
        'failed',
    ];

    public function key(): string
    {
        return 'flow_routes.preset_definition';
    }

    public function owner(): string
    {
        return 'flow_routes';
    }

    public function sourcePattern(): string
    {
        return 'presets.modules.{contributor}.flow-routes.definitions.{route_key}';
    }

    public function schema(): ConfigSchema
    {
        $openSchema = $this->openSchema();
        $emptySchema = $this->emptySchema();

        $pointOptions = [];

        foreach ($this->pointDefinitionSchemas($openSchema, $emptySchema) as $type => $definitionSchema) {
            $pointOptions[] = $this->pointSchema(
                type: $type,
                definitionSchema: $definitionSchema,
                openSchema: $openSchema,
                emptySchema: $emptySchema,
            );
        }

        return ConfigSchema::object([
            'key' => ConfigField::required(
                ConfigSchema::string(),
            ),

            'contact_status_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
                referenceTarget: 'contact_statuses',
            ),

            'name' => ConfigField::required(
                ConfigSchema::string(),
            ),

            'description' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
            ),

            'version' => ConfigField::defaulted(
                ConfigSchema::integer(),
                1,
            ),

            'is_active' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                true,
            ),

            'source_version' => ConfigField::optional(
                $this->sourceVersionSchema(),
            ),

            'owner_type' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
            ),

            'owner_id' => ConfigField::optional(
                ConfigSchema::integer(nullable: true),
            ),

            'owner_group' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
            ),

            'trigger' => ConfigField::optional(
                $this->triggerSchema(),
            ),

            'points' => ConfigField::required(
                ConfigSchema::listOf(
                    ConfigSchema::oneOf($pointOptions),
                ),
            ),

            'meta' => ConfigField::defaulted(
                $openSchema,
                [],
            ),
        ], atLeastOne: [
            ['contact_status_key', 'trigger'],
        ], atMostOne: [
            ['contact_status_key', 'trigger'],
        ]);
    }

    public function example(): array
    {
        return [
            'key' => 'example',
            'name' => 'Example',
            'trigger' => [
                'type' => 'manual',
            ],
            'points' => [
                [
                    'key' => 'start',
                    'type' => 'noop',
                    'is_start' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, ConfigSchema>
     */
    private function pointDefinitionSchemas(
        ConfigSchema $openSchema,
        ConfigSchema $emptySchema,
    ): array {
        return [
            'noop' => $emptySchema,

            'wait' => $this->waitDefinitionSchema(),

            'event_wait' => $this->eventWaitDefinitionSchema(
                $openSchema,
            ),

            'condition' => $this->conditionDefinitionSchema(
                $openSchema,
            ),

            'branch_evaluate' => $this->branchEvaluateDefinitionSchema(
                $openSchema,
            ),

            'change_status' => $this->changeStatusDefinitionSchema(
                $openSchema,
            ),

            'create_task' => $this->createTaskDefinitionSchema(
                $openSchema,
            ),

            'send_message' => $this->sendMessageDefinitionSchema(
                $openSchema,
            ),

            'enroll_campaign' => $this->enrollCampaignDefinitionSchema(
                $openSchema,
            ),

            'cancel_campaign' => $this->cancelCampaignDefinitionSchema(
                $openSchema,
            ),
        ];
    }

    private function waitDefinitionSchema(): ConfigSchema
    {
        $timingFields = [
            'resume_at',
            'seconds',
            'minutes',
            'hours',
            'days',
            'weeks',
        ];

        return ConfigSchema::object([
            'resume_at' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'timezone' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'seconds' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'minutes' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'hours' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'days' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'weeks' => ConfigField::optional(
                ConfigSchema::integer(),
            ),
        ], atLeastOne: [
            $timingFields,
        ], atMostOne: [
            $timingFields,
        ]);
    }

    private function eventWaitDefinitionSchema(
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'event_key' => ConfigField::required(
                ConfigSchema::string(),
            ),

            'correlation' => ConfigField::defaulted(
                $openSchema,
                [],
            ),

            'meta' => ConfigField::defaulted(
                $openSchema,
                [],
            ),
        ]);
    }

    private function conditionDefinitionSchema(
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'conditions' => ConfigField::required(
                ConfigSchema::listOf($openSchema),
            ),

            'mode' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: ConditionPointDefinition::MODES,
                ),
                ConditionPointDefinition::MODE_ALL,
            ),

            'on_pass' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: self::OUTCOMES,
                ),
                'completed',
            ),

            'on_fail' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: self::OUTCOMES,
                ),
                'blocked',
            ),
        ]);
    }

    private function branchEvaluateDefinitionSchema(
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'branches' => ConfigField::optional(
                ConfigSchema::listOf($openSchema),
            ),

            'mode' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: ConditionPointDefinition::MODES,
                ),
                ConditionPointDefinition::MODE_ALL,
            ),

            'on_no_match' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: BranchEvaluatePointDefinition::ON_NO_MATCH_ACTIONS,
                ),
                BranchEvaluatePointDefinition::ON_NO_MATCH_BLOCKED,
            ),

            'default_target_flow_route_point_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
            ),
        ], atLeastOne: [
            [
                'branches',
                'default_target_flow_route_point_key',
            ],
        ]);
    }

    private function changeStatusDefinitionSchema(
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'contact_status_id' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'contact_status_key' => ConfigField::optional(
                ConfigSchema::string(),
                referenceTarget: 'contact_statuses',
            ),

            'reason' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'force' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                false,
            ),

            'on_same_status' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: self::OUTCOMES,
                ),
                'skipped',
            ),

            'meta' => ConfigField::defaulted(
                $openSchema,
                [],
            ),
        ], atLeastOne: [
            [
                'contact_status_id',
                'contact_status_key',
            ],
        ], atMostOne: [
            [
                'contact_status_id',
                'contact_status_key',
            ],
        ]);
    }

    private function createTaskDefinitionSchema(
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'title' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'task_template_key' => ConfigField::optional(
                ConfigSchema::string(),
                referenceTarget: 'task_templates',
            ),

            'assigned_to_id' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'assigned_to_type' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'assigned_to_strategy' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'responsible_party' => ConfigField::optional(
                ConfigSchema::string(
                    allowedValues: Task::RESPONSIBLE_PARTY_OPTIONS,
                ),
            ),

            'responsible_type' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'responsible_id' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'description' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'due_at' => ConfigField::optional(
                ConfigSchema::mixed(),
            ),

            'due_offset_minutes' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'priority' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'meta' => ConfigField::defaulted(
                $openSchema,
                [],
            ),
        ], atLeastOne: [
            [
                'title',
                'task_template_key',
            ],
        ]);
    }

    private function sendMessageDefinitionSchema(
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'channel' => ConfigField::required(
                ConfigSchema::string(
                    allowedValues: [
                        'email',
                        'sms',
                    ],
                ),
            ),

            'purpose' => ConfigField::required(
                ConfigSchema::string(),
            ),

            'scope' => ConfigField::required(
                ConfigSchema::string(),
            ),

            'dispatch_key' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'dispatch_keys' => ConfigField::optional(
                ConfigSchema::listOf(
                    ConfigSchema::string(),
                ),
            ),

            'payload' => ConfigField::defaulted(
                $openSchema,
                [],
            ),

            'criteria' => ConfigField::defaulted(
                $openSchema,
                [],
            ),

            'anchor' => ConfigField::optional(
                ConfigSchema::mixed(),
            ),

            'on_no_messages' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: self::OUTCOMES,
                ),
                'skipped',
            ),

            'meta' => ConfigField::defaulted(
                $openSchema,
                [],
            ),
        ], atLeastOne: [
            [
                'dispatch_key',
                'dispatch_keys',
            ],
        ]);
    }

    private function enrollCampaignDefinitionSchema(
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'campaign_key' => ConfigField::required(
                ConfigSchema::string(),
                referenceTarget: 'campaigns',
            ),

            'on_already_enrolled' => ConfigField::defaulted(
                ConfigSchema::string(
                    allowedValues: EnrollCampaignPointDefinition::ON_ALREADY_ENROLLED_OPTIONS,
                ),
                EnrollCampaignPointDefinition::ON_ALREADY_ENROLLED_SKIPPED,
            ),

            'payload' => ConfigField::defaulted(
                $openSchema,
                [],
            ),

            'meta' => ConfigField::defaulted(
                $openSchema,
                [],
            ),

            'start_context' => ConfigField::optional(
                ConfigSchema::oneOf(
                    [$openSchema],
                    nullable: true,
                ),
            ),

            'exit_conditions' => ConfigField::optional(
                ConfigSchema::oneOf(
                    [$openSchema],
                    nullable: true,
                ),
            ),
        ]);
    }

    private function cancelCampaignDefinitionSchema(
        ConfigSchema $openSchema,
    ): ConfigSchema {
        return ConfigSchema::object([
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
                    allowedValues: CancelCampaignPointDefinition::ON_NOT_ENROLLED_OPTIONS,
                ),
                CancelCampaignPointDefinition::ON_NOT_ENROLLED_SKIPPED,
            ),

            'skip_pending_messages' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                true,
            ),

            'meta' => ConfigField::defaulted(
                $openSchema,
                [],
            ),
        ]);
    }

    private function pointSchema(
        string $type,
        ConfigSchema $definitionSchema,
        ConfigSchema $openSchema,
        ConfigSchema $emptySchema,
    ): ConfigSchema {
        return ConfigSchema::object([
            'key' => ConfigField::required(
                ConfigSchema::string(),
            ),

            'type' => ConfigField::required(
                ConfigSchema::string(
                    allowedValues: [$type],
                ),
            ),

            'name' => ConfigField::optional(
                ConfigSchema::string(),
            ),

            'description' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
            ),

            'capability_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
                referenceTarget: 'flow_route_capabilities',
            ),

            'sort_order' => ConfigField::optional(
                ConfigSchema::integer(),
            ),

            'is_start' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                false,
            ),

            'is_active' => ConfigField::defaulted(
                ConfigSchema::boolean(),
                true,
            ),

            'next_point_key' => ConfigField::optional(
                ConfigSchema::string(nullable: true),
                referenceTarget: 'flow_route_points',
            ),

            'definition' => ConfigField::defaulted(
                $definitionSchema,
                [],
            ),

            'settings' => ConfigField::defaulted(
                $emptySchema,
                [],
            ),

            'cancel_conditions' => ConfigField::defaulted(
                ConfigSchema::listOf($openSchema),
                [],
            ),

            'source_version' => ConfigField::optional(
                $this->sourceVersionSchema(),
            ),

            'meta' => ConfigField::defaulted(
                $openSchema,
                [],
            ),
        ]);
    }

    private function triggerSchema(): ConfigSchema
    {
        return ConfigSchema::oneOf([
            ConfigSchema::object([
                'type' => ConfigField::required(
                    ConfigSchema::string(
                        allowedValues: ['manual'],
                    ),
                ),
            ]),

            ConfigSchema::object([
                'type' => ConfigField::required(
                    ConfigSchema::string(
                        allowedValues: ['automation_event'],
                    ),
                ),

                'event_key' => ConfigField::required(
                    ConfigSchema::string(),
                    referenceTarget: 'automation_events',
                ),
            ]),
        ]);
    }

    private function sourceVersionSchema(): ConfigSchema
    {
        return ConfigSchema::oneOf([
            ConfigSchema::string(),
            ConfigSchema::integer(),
            ConfigSchema::number(),
        ], nullable: true);
    }

    private function openSchema(): ConfigSchema
    {
        return ConfigSchema::object(
            [],
            allowUnknown: true,
        );
    }

    private function emptySchema(): ConfigSchema
    {
        return ConfigSchema::object([]);
    }
}