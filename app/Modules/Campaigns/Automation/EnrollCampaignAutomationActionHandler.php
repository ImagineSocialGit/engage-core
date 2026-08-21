<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Data\Automation\EnrollCampaignAutomationDefinition;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use InvalidArgumentException;
use Throwable;

class EnrollCampaignAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly EnrollContactInCampaignAction $enrollContactInCampaign,
    ) {}

    public function key(): string
    {
        return 'campaigns.enroll_contact';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = EnrollCampaignAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_enroll_campaign_automation_definition',
                output: ['enroll_campaign_definition' => $definition->toMetaPayload()],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed('enroll_campaign_contact_not_found', output: [
                'enroll_campaign_definition' => $definition->toMetaPayload(),
            ]);
        }

        $campaign = Campaign::query()
            ->where('key', $definition->campaignKey)
            ->first();

        if (! $campaign instanceof Campaign) {
            return AutomationActionResult::skipped(
                reason: CampaignUnavailableForEnrollmentException::REASON_MISSING,
                output: [
                    'campaign_key' => $definition->campaignKey,
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        if (! $campaign->isActive()) {
            return AutomationActionResult::skipped(
                reason: CampaignUnavailableForEnrollmentException::REASON_INACTIVE,
                output: [
                    'campaign_key' => $campaign->key,
                    'campaign_status' => $campaign->status,
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        $existingEnrollment = $this->existingOpenEnrollment(
            contact: $contact,
            campaign: $campaign,
        );

        if ($existingEnrollment instanceof CampaignEnrollment) {
            return $this->alreadyEnrolledResult($existingEnrollment, $definition);
        }

        try {
            $enrollment = $this->enrollContactInCampaign->handle(
                contact: $contact,
                campaignKey: $definition->campaignKey,
                source: $context->source ?? $contact,
                payload: $this->withRuntimeContext($definition->payload, $context),
                meta: array_replace_recursive(
                    ['source' => 'automation'],
                    $context->meta,
                    $definition->meta,
                ),
                startContext: $definition->startContext === null
                    ? null
                    : array_replace_recursive(
                        $context->runtimeContext,
                        $definition->startContext,
                    ),
                exitConditions: $definition->exitConditions,
                entryKey: $this->entryKey($context, $definition->campaignKey),
            );
        } catch (CampaignUnavailableForEnrollmentException $exception) {
            return AutomationActionResult::skipped(
                reason: $exception->reason,
                output: [
                    'campaign_key' => $exception->campaignKey,
                    'campaign_status' => $exception->campaignStatus,
                    'campaign_family_key' => $exception->familyKey,
                    'campaign_priority' => $exception->campaignPriority,
                    'blocking_campaign_key' => $exception->blockingCampaignKey,
                    'blocking_campaign_priority' => $exception->blockingPriority,
                    'blocking_campaign_enrollment_id' => $exception->blockingEnrollmentId,
                    'error' => $exception->getMessage(),
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        } catch (InvalidArgumentException $exception) {
            return AutomationActionResult::skipped(
                reason: 'campaign_enrollment_not_schedulable',
                output: [
                    'error' => $exception->getMessage(),
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        } catch (Throwable $exception) {
            return AutomationActionResult::failed(
                reason: 'enroll_campaign_failed',
                output: [
                    'error' => $exception->getMessage(),
                    'enroll_campaign_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        return AutomationActionResult::completed(
            reason: 'campaign_enrolled',
            artifacts: [$enrollment],
            correlationKey: 'campaign_enrollment.id',
            correlationType: 'campaign_enrollment',
            correlation: [
                'campaign_enrollment_id' => $enrollment->getKey(),
                'campaign_key' => $enrollment->campaign_key,
                'message_chain_enrollment_id' => $enrollment->message_chain_enrollment_id,
            ],
            output: [
                'campaign_enrollment' => $this->enrollmentMeta($enrollment),
                'enroll_campaign_definition' => $definition->toMetaPayload(),
            ],
        );
    }


    private function entryKey(
        AutomationActionContext $context,
        string $campaignKey,
    ): ?string {
        if (! is_string($context->executionKey) || trim($context->executionKey) === '') {
            return null;
        }

        return implode(':', [
            'automation',
            trim($context->executionKey),
            $campaignKey,
        ]);
    }

    private function existingOpenEnrollment(
        Contact $contact,
        Campaign $campaign,
    ): ?CampaignEnrollment {
        return CampaignEnrollment::query()
            ->with('messageChainEnrollment')
            ->where('contact_id', $contact->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->whereNotNull('message_chain_enrollment_id')
            ->whereHas(
                'messageChainEnrollment',
                fn ($query) => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
            ->orderByDesc('id')
            ->first();
    }

    private function alreadyEnrolledResult(
        CampaignEnrollment $enrollment,
        EnrollCampaignAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'campaign_enrollment' => $this->enrollmentMeta($enrollment),
            'enroll_campaign_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onAlreadyEnrolled) {
            EnrollCampaignAutomationDefinition::ON_ALREADY_ENROLLED_COMPLETED => AutomationActionResult::completed(
                reason: 'campaign_already_enrolled',
                artifacts: [$enrollment],
                output: $output,
            ),
            EnrollCampaignAutomationDefinition::ON_ALREADY_ENROLLED_BLOCKED => AutomationActionResult::blocked(
                reason: 'campaign_already_enrolled',
                output: $output,
            ),
            EnrollCampaignAutomationDefinition::ON_ALREADY_ENROLLED_FAILED => AutomationActionResult::failed(
                reason: 'campaign_already_enrolled',
                output: $output,
            ),
            default => AutomationActionResult::skipped(
                reason: 'campaign_already_enrolled',
                output: $output,
            ),
        };
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withRuntimeContext(array $payload, AutomationActionContext $context): array
    {
        if ($context->runtimeContext === []) {
            return $payload;
        }

        return array_replace_recursive($payload, [
            'runtime_context' => $context->runtimeContext,
        ]);
    }

    /** @return array<string, mixed> */
    private function enrollmentMeta(CampaignEnrollment $enrollment): array
    {
        $enrollment->loadMissing('messageChainEnrollment');
        $chainEnrollment = $enrollment->messageChainEnrollment;

        return [
            'id' => $enrollment->getKey(),
            'contact_id' => $enrollment->contact_id,
            'campaign_id' => $enrollment->campaign_id,
            'campaign_key' => $enrollment->campaign_key,
            'message_chain_enrollment_id' => $chainEnrollment?->getKey(),
            'status' => $chainEnrollment?->status,
            'message_chain_status' => $chainEnrollment?->status,
            'message_chain_version_id' => $chainEnrollment?->message_chain_version_id,
            'current_message_chain_step_id' => $chainEnrollment?->current_message_chain_step_id,
            'next_action_at' => $chainEnrollment?->next_action_at?->toISOString(),
            'started_at' => $chainEnrollment?->started_at?->toISOString()
                ?? $enrollment->started_at?->toISOString(),
            'paused_at' => $chainEnrollment?->paused_at?->toISOString(),
            'resumed_at' => $chainEnrollment?->resumed_at?->toISOString(),
            'exited_at' => $chainEnrollment?->exited_at?->toISOString(),
            'completed_at' => $chainEnrollment?->completed_at?->toISOString(),
            'cancelled_at' => $chainEnrollment?->cancelled_at?->toISOString(),
            'exit_reason' => $chainEnrollment?->exit_reason_code,
        ];
    }
}