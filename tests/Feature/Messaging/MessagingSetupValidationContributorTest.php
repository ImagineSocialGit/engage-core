<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessagingSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.email', []);
        Config::set('messaging.sms', []);

        Config::set('reference.tokens', [
            'models' => [
                'contact' => [
                    'tokens' => [
                        '{first_name}',
                        '{contact.first_name}',
                    ],
                ],
            ],
        ]);
    }

    public function test_it_accepts_valid_config_and_empty_db_runtime_state(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Hi {first_name}, thanks for registering.',
                ],
            ],
        ]);

        $this->assertSame([], $this->findings());
    }

    public function test_it_adapts_message_config_validator_issues_into_shared_findings(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => 'Missing\\Payload',
                'queue' => '',
                'payload' => [
                    'subject' => '',
                ],
            ],
        ]);

        $findings = $this->findings();
        $messages = array_column($findings, 'message');

        $this->assertContains('Payload class does not exist.', $messages);
        $this->assertContains('Message definition has invalid [queue].', $messages);
        $this->assertContains('Email payload requires a body.', $messages);
    }

    public function test_it_validates_customized_db_presets_with_the_existing_message_validator(): void
    {
        MessageTemplatePreset::query()->create([
            'key' => 'custom.invalid',
            'name' => 'Invalid customized preset',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => 'Missing\\Payload',
            'queue' => '',
            'dispatch_keys' => [],
            'payload' => [
                'subject' => 'Subject only',
            ],
            'tokens' => [],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'source' => 'manual',
            'is_customized' => true,
            'meta' => [],
        ]);

        $messages = array_column($this->findings(), 'message');

        $this->assertContains('Payload class does not exist.', $messages);
        $this->assertContains('Message definition has invalid [dispatch_key] or [dispatch_keys].', $messages);
        $this->assertContains('Message definition has invalid [queue].', $messages);
        $this->assertContains('Email payload requires a body.', $messages);
    }

    public function test_it_reports_active_assignment_to_inactive_preset(): void
    {
        $preset = $this->preset([
            'key' => 'inactive.preset',
            'status' => MessageTemplatePreset::STATUS_INACTIVE,
            'is_active' => false,
        ]);

        MessageTemplatePresetAssignment::query()->create(
            $this->assignmentAttributes($preset)
        );

        $this->assertContains(
            'messaging.assignment_preset_inactive',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_reports_incomplete_assignment_context_and_campaign_identity(): void
    {
        $preset = $this->preset([
            'key' => 'context.invalid',
        ]);

        MessageTemplatePresetAssignment::query()->create(
            $this->assignmentAttributes($preset, [
                'campaign_key' => 'webinar_attended_nurture',
                'campaign_step' => null,
                'context_type' => 'webinar_series',
                'context_id' => null,
            ])
        );

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('messaging.assignment_context_incomplete', $codes);
        $this->assertContains('messaging.assignment_campaign_context_incomplete', $codes);
    }

    public function test_it_reports_exact_active_assignment_ambiguity_using_runtime_identity_dimensions(): void
    {
        $firstPreset = $this->preset([
            'key' => 'confirmation.first',
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmations.0',
        ]);

        $secondPreset = $this->preset([
            'key' => 'confirmation.second',
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmations.0',
        ]);

        MessageTemplatePresetAssignment::query()->create(
            $this->assignmentAttributes($firstPreset)
        );

        MessageTemplatePresetAssignment::query()->create(
            $this->assignmentAttributes($secondPreset)
        );

        $ambiguities = array_values(array_filter(
            $this->findings(),
            fn (array $finding): bool => $finding['code'] === 'messaging.assignment_exact_ambiguity',
        ));

        $this->assertCount(1, $ambiguities);
        $this->assertCount(2, $ambiguities[0]['context']['assignment_ids']);
    }

    public function test_distinct_variant_or_context_assignments_are_not_treated_as_exact_ambiguity(): void
    {
        $firstPreset = $this->preset([
            'key' => 'campaign.email.primary',
            'message_type' => 'webinar_attended_nurture_step_1',
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email_primary',
        ]);

        $secondPreset = $this->preset([
            'key' => 'campaign.email.alternate',
            'message_type' => 'webinar_attended_nurture_step_1',
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email_alternate',
        ]);

        MessageTemplatePresetAssignment::query()->create(
            $this->assignmentAttributes($firstPreset, [
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'surface' => 'campaigns',
                'message_type' => 'webinar_attended_nurture_step_1',
                'campaign_key' => 'webinar_attended_nurture',
                'campaign_step' => 1,
                'campaign_step_variant_key' => 'email_primary',
                'source_config_path' => $firstPreset->source_config_path,
            ])
        );

        MessageTemplatePresetAssignment::query()->create(
            $this->assignmentAttributes($secondPreset, [
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'surface' => 'campaigns',
                'message_type' => 'webinar_attended_nurture_step_1',
                'campaign_key' => 'webinar_attended_nurture',
                'campaign_step' => 1,
                'campaign_step_variant_key' => 'email_alternate',
                'source_config_path' => $secondPreset->source_config_path,
            ])
        );

        $this->assertNotContains(
            'messaging.assignment_exact_ambiguity',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_manager_resolves_tagged_messaging_contributor(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => 'Missing\\Payload',
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],
        ]);

        $result = app(SetupValidationManager::class)->validate();

        $this->assertContains(
            'Payload class does not exist.',
            array_map(
                fn (SetupValidationFinding $finding): string => $finding->message,
                $result->findings(),
            ),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function preset(array $overrides = []): MessageTemplatePreset
    {
        return MessageTemplatePreset::query()->create(array_replace([
            'key' => 'confirmation.default',
            'name' => 'Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Registered',
                'body' => 'Thanks',
            ],
            'tokens' => [],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'source' => 'manual',
            'is_customized' => false,
            'meta' => [],
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function assignmentAttributes(
        MessageTemplatePreset $preset,
        array $overrides = [],
    ): array {
        return array_replace([
            'message_template_preset_id' => $preset->getKey(),
            'channel' => $preset->channel,
            'purpose' => $preset->purpose,
            'scope' => $preset->scope,
            'surface' => null,
            'message_type' => $preset->message_type,
            'campaign_key' => null,
            'campaign_step' => null,
            'campaign_step_variant_key' => null,
            'source_config_path' => null,
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'meta' => [],
        ], $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(MessagingSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}
