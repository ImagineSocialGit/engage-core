<?php

namespace App\Modules\FlowRoutes\ConfigContracts;

use App\Modules\FlowRoutes\Data\Points\BranchEvaluatePointDefinition;
use App\Modules\FlowRoutes\Data\Points\CancelCampaignPointDefinition;
use App\Modules\FlowRoutes\Data\Points\ConditionPointDefinition;
use App\Modules\FlowRoutes\Data\Points\EnrollCampaignPointDefinition;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class FlowRoutePresetDefinitionConfigContract implements ConfigContract
{
    public function key(): string { return 'flow_routes.preset_definition'; }
    public function owner(): string { return 'flow_routes'; }
    public function sourcePattern(): string { return 'presets.modules.{contributor}.flow-routes.definitions.{route_key}'; }

    public function schema(): ConfigSchema
    {
        $open = ConfigSchema::object([], allowUnknown: true);
        $empty = ConfigSchema::object([]);
        $outcomes = ['completed', 'skipped', 'blocked', 'failed'];
        $definitions = [
            'noop' => $empty,
            'wait' => ConfigSchema::object([
                'resume_at' => ConfigField::optional(ConfigSchema::string()), 'timezone' => ConfigField::optional(ConfigSchema::string()),
                'seconds' => ConfigField::optional(ConfigSchema::integer()), 'minutes' => ConfigField::optional(ConfigSchema::integer()), 'hours' => ConfigField::optional(ConfigSchema::integer()), 'days' => ConfigField::optional(ConfigSchema::integer()), 'weeks' => ConfigField::optional(ConfigSchema::integer()),
            ], atLeastOne: [['resume_at', 'seconds', 'minutes', 'hours', 'days', 'weeks']], atMostOne: [['resume_at', 'seconds', 'minutes', 'hours', 'days', 'weeks']]),
            'event_wait' => ConfigSchema::object(['event_key' => ConfigField::required(ConfigSchema::string()), 'correlation' => ConfigField::defaulted($open, []), 'meta' => ConfigField::defaulted($open, [])]),
            'condition' => ConfigSchema::object(['conditions' => ConfigField::required(ConfigSchema::listOf($open)), 'mode' => ConfigField::defaulted(ConfigSchema::string(allowedValues: ConditionPointDefinition::MODES), ConditionPointDefinition::MODE_ALL), 'on_pass' => ConfigField::defaulted(ConfigSchema::string(allowedValues: $outcomes), 'completed'), 'on_fail' => ConfigField::defaulted(ConfigSchema::string(allowedValues: $outcomes), 'blocked')]),
            'branch_evaluate' => ConfigSchema::object(['branches' => ConfigField::optional(ConfigSchema::listOf($open)), 'mode' => ConfigField::defaulted(ConfigSchema::string(allowedValues: ConditionPointDefinition::MODES), ConditionPointDefinition::MODE_ALL), 'on_no_match' => ConfigField::defaulted(ConfigSchema::string(allowedValues: BranchEvaluatePointDefinition::ON_NO_MATCH_ACTIONS), BranchEvaluatePointDefinition::ON_NO_MATCH_BLOCKED), 'default_target_flow_route_point_key' => ConfigField::optional(ConfigSchema::string(nullable: true))], atLeastOne: [['branches', 'default_target_flow_route_point_key']]),
            'change_status' => ConfigSchema::object(['contact_status_id' => ConfigField::optional(ConfigSchema::integer()), 'contact_status_key' => ConfigField::optional(ConfigSchema::string(), referenceTarget: 'contact_statuses'), 'reason' => ConfigField::optional(ConfigSchema::string()), 'force' => ConfigField::defaulted(ConfigSchema::boolean(), false), 'on_same_status' => ConfigField::defaulted(ConfigSchema::string(allowedValues: $outcomes), 'skipped'), 'meta' => ConfigField::defaulted($open, [])], atLeastOne: [['contact_status_id', 'contact_status_key']], atMostOne: [['contact_status_id', 'contact_status_key']]),
            'create_task' => ConfigSchema::object(['title' => ConfigField::optional(ConfigSchema::string()), 'task_template_key' => ConfigField::optional(ConfigSchema::string(), referenceTarget: 'task_templates'), 'assigned_to_id' => ConfigField::optional(ConfigSchema::integer()), 'assigned_to_type' => ConfigField::optional(ConfigSchema::string()), 'assigned_to_strategy' => ConfigField::optional(ConfigSchema::string(allowedValues: TaskTemplate::ASSIGNED_TO_STRATEGIES)), 'responsible_party' => ConfigField::optional(ConfigSchema::string(allowedValues: Task::RESPONSIBLE_PARTY_OPTIONS)), 'responsible_type' => ConfigField::optional(ConfigSchema::string()), 'responsible_id' => ConfigField::optional(ConfigSchema::integer()), 'description' => ConfigField::optional(ConfigSchema::string()), 'due_at' => ConfigField::optional(ConfigSchema::mixed()), 'due_offset_minutes' => ConfigField::optional(ConfigSchema::integer()), 'priority' => ConfigField::optional(ConfigSchema::string()), 'meta' => ConfigField::defaulted($open, [])], atLeastOne: [['title', 'task_template_key']]),
            'send_message' => ConfigSchema::object(['channel' => ConfigField::required(ConfigSchema::string(allowedValues: ['email', 'sms'])), 'purpose' => ConfigField::required(ConfigSchema::string()), 'scope' => ConfigField::required(ConfigSchema::string()), 'dispatch_key' => ConfigField::optional(ConfigSchema::string()), 'dispatch_keys' => ConfigField::optional(ConfigSchema::listOf(ConfigSchema::string())), 'payload' => ConfigField::defaulted($open, []), 'criteria' => ConfigField::defaulted($open, []), 'anchor' => ConfigField::optional(ConfigSchema::mixed()), 'on_no_messages' => ConfigField::defaulted(ConfigSchema::string(allowedValues: $outcomes), 'skipped'), 'meta' => ConfigField::defaulted($open, [])], atLeastOne: [['dispatch_key', 'dispatch_keys']]),
            'enroll_campaign' => ConfigSchema::object(['campaign_key' => ConfigField::required(ConfigSchema::string(), referenceTarget: 'campaigns'), 'on_already_enrolled' => ConfigField::defaulted(ConfigSchema::string(allowedValues: EnrollCampaignPointDefinition::ON_ALREADY_ENROLLED_OPTIONS), EnrollCampaignPointDefinition::ON_ALREADY_ENROLLED_SKIPPED), 'payload' => ConfigField::defaulted($open, []), 'meta' => ConfigField::defaulted($open, []), 'start_context' => ConfigField::optional(ConfigSchema::oneOf([$open], nullable: true)), 'exit_conditions' => ConfigField::optional(ConfigSchema::oneOf([$open], nullable: true))]),
            'cancel_campaign' => ConfigSchema::object(['campaign_key' => ConfigField::required(ConfigSchema::string(), referenceTarget: 'campaigns'), 'reason' => ConfigField::defaulted(ConfigSchema::string(), 'flow_route_cancelled_campaign'), 'on_not_enrolled' => ConfigField::defaulted(ConfigSchema::string(allowedValues: CancelCampaignPointDefinition::ON_NOT_ENROLLED_OPTIONS), CancelCampaignPointDefinition::ON_NOT_ENROLLED_SKIPPED), 'skip_pending_messages' => ConfigField::defaulted(ConfigSchema::boolean(), true), 'meta' => ConfigField::defaulted($open, [])]),
        ];
        $pointOptions = [];
        foreach ($definitions as $type => $definition) {
            $pointOptions[] = ConfigSchema::object([
                'key' => ConfigField::required(ConfigSchema::string()), 'type' => ConfigField::required(ConfigSchema::string(allowedValues: [$type])), 'name' => ConfigField::optional(ConfigSchema::string()), 'description' => ConfigField::optional(ConfigSchema::string(nullable: true)), 'capability_key' => ConfigField::optional(ConfigSchema::string(nullable: true), referenceTarget: 'flow_route_capabilities'), 'sort_order' => ConfigField::optional(ConfigSchema::integer()), 'is_start' => ConfigField::defaulted(ConfigSchema::boolean(), false), 'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true), 'next_point_key' => ConfigField::optional(ConfigSchema::string(nullable: true), referenceTarget: 'flow_route_points'), 'definition' => ConfigField::defaulted($definition, []), 'settings' => ConfigField::defaulted($empty, []), 'cancel_conditions' => ConfigField::defaulted(ConfigSchema::listOf($open), []), 'source_version' => ConfigField::optional(ConfigSchema::oneOf([ConfigSchema::string(), ConfigSchema::integer(), ConfigSchema::number()], nullable: true)), 'meta' => ConfigField::defaulted($open, []),
            ]);
        }
        $trigger = ConfigSchema::oneOf([
            ConfigSchema::object(['type' => ConfigField::required(ConfigSchema::string(allowedValues: ['manual']))]),
            ConfigSchema::object(['type' => ConfigField::required(ConfigSchema::string(allowedValues: ['automation_event'])), 'event_key' => ConfigField::required(ConfigSchema::string(), referenceTarget: 'automation_events')]),
        ]);
        return ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()), 'contact_status_key' => ConfigField::optional(ConfigSchema::string(nullable: true), referenceTarget: 'contact_statuses'), 'name' => ConfigField::required(ConfigSchema::string()), 'description' => ConfigField::optional(ConfigSchema::string(nullable: true)), 'version' => ConfigField::defaulted(ConfigSchema::integer(), 1), 'is_active' => ConfigField::defaulted(ConfigSchema::boolean(), true), 'source_version' => ConfigField::optional(ConfigSchema::oneOf([ConfigSchema::string(), ConfigSchema::integer(), ConfigSchema::number()], nullable: true)), 'owner_type' => ConfigField::optional(ConfigSchema::string(nullable: true)), 'owner_id' => ConfigField::optional(ConfigSchema::integer(nullable: true)), 'owner_group' => ConfigField::optional(ConfigSchema::string(nullable: true)), 'trigger' => ConfigField::optional($trigger), 'points' => ConfigField::required(ConfigSchema::listOf(ConfigSchema::oneOf($pointOptions))), 'meta' => ConfigField::defaulted($open, []),
        ], atLeastOne: [['contact_status_key', 'trigger']], atMostOne: [['contact_status_key', 'trigger']]);
    }
    public function example(): array { return ['key' => 'example', 'name' => 'Example', 'trigger' => ['type' => 'manual'], 'points' => [['key' => 'start', 'type' => 'noop', 'is_start' => true]]]; }
}
