<?php

namespace Tests\Feature\ConfigContracts;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use App\Support\ConfigContracts\ConfigContractRegistry;
use Tests\TestCase;

class MessagingConfigContractTest extends TestCase
{
    public function test_every_current_default_message_definition_matches_its_channel_contract(): void
    {
        $registry = app(ConfigContractRegistry::class);

        foreach (['email', 'sms'] as $channel) {
            $contract = $registry->get("messaging.{$channel}_definition");

            foreach (['transactional', 'marketing', 'internal'] as $purpose) {
                foreach (config(MessageDefinitionConfigPath::purpose($channel, $purpose), []) as $scope => $scopeConfig) {
                    if (! is_array($scopeConfig)) {
                        continue;
                    }

                    foreach ($this->definitions($scopeConfig) as $path => $definition) {
                        $violations = $contract->schema()->validate(
                            $definition,
                            MessageDefinitionConfigPath::scope($channel, $purpose, (string) $scope).".{$path}",
                        );

                        $this->assertSame(
                            [],
                            $violations,
                            "Default message definition [{$channel}.{$purpose}.{$scope}.{$path}] violates its contract.",
                        );
                    }
                }
            }
        }
    }

    public function test_current_permission_invitation_config_matches_its_closed_contract(): void
    {
        $violations = app(ConfigContractRegistry::class)
            ->get('messaging.permission_invitation')
            ->schema()
            ->validate(config('messaging.permission_invitations'), 'messaging.permission_invitations');

        $this->assertSame([], $violations);
    }

    public function test_email_and_sms_contracts_reject_cross_channel_and_behavior_fields(): void
    {
        $registry = app(ConfigContractRegistry::class);

        $emailViolations = $registry->get('messaging.email_definition')->schema()->validate([
            'channel' => 'sms',
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'timing' => 'immediate',
            'payload' => [
                'subject' => 'Subject',
                'body' => 'Body',
            ],
        ], 'messaging.email.definitions.marketing.example');

        $smsViolations = $registry->get('messaging.sms_definition')->schema()->validate([
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'body' => 'Compatibility aliases are not exported schema.',
            ],
        ], 'messaging.sms.definitions.marketing.example');

        $this->assertSame(['unknown_field', 'value_not_allowed'], $this->codes($emailViolations));
        $this->assertSame(['unknown_field', 'required_field_missing'], $this->codes($smsViolations));
    }

    public function test_message_contract_requires_dispatch_key_or_dispatch_keys(): void
    {
        $violations = app(ConfigContractRegistry::class)
            ->get('messaging.email_definition')
            ->schema()
            ->validate([
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Subject',
                    'body' => 'Body',
                ],
            ], 'messaging.email.definitions.marketing.example');

        $this->assertSame(['required_field_group_missing'], $this->codes($violations));
    }

    public function test_permission_invitation_contract_rejects_invented_copy_and_style_fields(): void
    {
        $violations = app(ConfigContractRegistry::class)
            ->get('messaging.permission_invitation')
            ->schema()
            ->validate([
                'content' => [
                    'heading' => 'Choose how you want to hear from us.',
                    'invented_behavior' => true,
                ],
            ], 'messaging.permission_invitations');

        $this->assertSame(['unknown_field'], $this->codes($violations));
    }

    /** @param array<int, object> $violations */
    private function codes(array $violations): array
    {
        return array_map(fn ($violation): string => $violation->code, $violations);
    }

    /**
     * @param array<string, mixed> $scopeConfig
     * @return iterable<string, array<string, mixed>>
     */
    private function definitions(array $scopeConfig): iterable
    {
        foreach ($scopeConfig as $messageType => $definition) {
            if ($messageType === 'campaigns' && is_array($definition)) {
                foreach ($definition as $campaignKey => $campaign) {
                    foreach (is_array($campaign) ? ($campaign['steps'] ?? []) : [] as $step => $stepDefinition) {
                        foreach (is_array($stepDefinition) ? ($stepDefinition['variants'] ?? []) : [] as $variant => $variantDefinition) {
                            if (is_array($variantDefinition)) {
                                yield "campaigns.{$campaignKey}.steps.{$step}.variants.{$variant}" => $variantDefinition;
                            }
                        }
                    }
                }

                continue;
            }

            if (! is_array($definition)) {
                continue;
            }

            if (array_is_list($definition)) {
                foreach ($definition as $index => $nestedDefinition) {
                    if (is_array($nestedDefinition)) {
                        yield "{$messageType}.{$index}" => $nestedDefinition;
                    }
                }

                continue;
            }

            yield (string) $messageType => $definition;
        }
    }
}
