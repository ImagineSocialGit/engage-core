<?php

namespace App\Modules\Relationships\Automation;

use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Actions\ChangeContactRelationshipStageAction;
use App\Modules\Relationships\Data\Automation\ChangeRelationshipStageAutomationDefinition;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use InvalidArgumentException;
use Throwable;

class ChangeRelationshipStageAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly ChangeContactRelationshipStageAction $changeStage,
    ) {}

    public function key(): string
    {
        return 'relationships.change_stage';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = ChangeRelationshipStageAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_change_relationship_stage_definition',
                output: [
                    'change_relationship_stage_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed(
                reason: 'change_relationship_stage_contact_not_found',
                output: [
                    'change_relationship_stage_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        try {
            $change = $this->changeStage->handleGuarded(
                contact: $contact,
                relationshipKey: $definition->relationshipKey,
                stageKey: $definition->stageKey,
                fromStageKey: $definition->fromStageKey,
            );
        } catch (InvalidArgumentException $exception) {
            return AutomationActionResult::failed(
                reason: 'change_relationship_stage_invalid_target',
                output: [
                    'error' => $exception->getMessage(),
                    'change_relationship_stage_definition' => $definition->toMetaPayload(),
                ],
            );
        } catch (Throwable $exception) {
            return AutomationActionResult::failed(
                reason: 'change_relationship_stage_failed',
                output: [
                    'error' => $exception->getMessage(),
                    'change_relationship_stage_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        $relationship = $change->relationship;

        if (! $relationship instanceof ContactRelationship) {
            return $this->missingRelationshipResult($definition);
        }

        if (! $change->guardMatched) {
            return AutomationActionResult::skipped(
                reason: 'relationship_stage_guard_not_matched',
                output: [
                    'contact_relationship' => $this->relationshipOutput(
                        relationship: $relationship,
                        previousStageKey: $change->previousStageKey,
                    ),
                    'change_relationship_stage_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        $reason = $change->changed
            ? 'relationship_stage_changed'
            : 'relationship_stage_unchanged';

        return AutomationActionResult::completed(
            reason: $reason,
            artifacts: [$relationship],
            correlationKey: 'contact_relationship.id',
            correlationType: 'contact_relationship',
            correlation: [
                'contact_relationship_id' => $relationship->getKey(),
                'relationship_key' => $relationship->relationship_key,
            ],
            output: [
                'contact_relationship' => $this->relationshipOutput(
                    relationship: $relationship,
                    previousStageKey: $change->previousStageKey,
                ),
                'change_relationship_stage_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    private function missingRelationshipResult(
        ChangeRelationshipStageAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'change_relationship_stage_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onMissingRelationship) {
            ChangeRelationshipStageAutomationDefinition::ON_MISSING_RELATIONSHIP_COMPLETED => AutomationActionResult::completed(
                reason: 'relationship_not_active',
                output: $output,
            ),
            ChangeRelationshipStageAutomationDefinition::ON_MISSING_RELATIONSHIP_BLOCKED => AutomationActionResult::blocked(
                reason: 'relationship_not_active',
                output: $output,
            ),
            ChangeRelationshipStageAutomationDefinition::ON_MISSING_RELATIONSHIP_FAILED => AutomationActionResult::failed(
                reason: 'relationship_not_active',
                output: $output,
            ),
            default => AutomationActionResult::skipped(
                reason: 'relationship_not_active',
                output: $output,
            ),
        };
    }

    /** @return array<string, mixed> */
    private function relationshipOutput(
        ContactRelationship $relationship,
        ?string $previousStageKey,
    ): array {
        return [
            'id' => $relationship->getKey(),
            'contact_id' => $relationship->contact_id,
            'relationship_key' => $relationship->relationship_key,
            'previous_stage_key' => $previousStageKey,
            'stage_key' => $relationship->stage_key,
            'is_active' => $relationship->is_active,
        ];
    }
}