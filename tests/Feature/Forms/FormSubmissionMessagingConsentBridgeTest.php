<?php

namespace Tests\Feature\Forms;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Forms\Actions\CreateFormSubmissionAction;
use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Validation\FormsSetupValidationContributor;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Services\MessageGate;
use App\Support\ModuleIntegrations\Forms\FormSubmissionConsentBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSubmissionMessagingConsentBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'core',
            'forms',
            'messaging',
        ]);
        $this->app->forgetInstance(FormSubmissionConsentBridge::class);
    }

    public function test_explicit_form_acceptance_grants_broad_channel_purpose_marketing_consent_without_using_interest_as_permission(): void
    {
        $this->publishedArtistForm();

        $first = app(CreateFormSubmissionAction::class)->handle(
            $this->artistInput(
                externalId: '8de2189c-7bba-42ce-bc61-cfdd70c936f8',
                emailConsent: true,
                smsConsent: false,
            ),
        );

        $contact = Contact::query()->findOrFail($first->contactId);
        $consent = MessageConsent::query()->sole();

        $this->assertSame(MessageChannel::Email, $consent->channel);
        $this->assertSame(MessagePurpose::Marketing, $consent->purpose);
        $this->assertSame('forms', $consent->scope);
        $this->assertSame('forms_submission', $consent->source);
        $this->assertSame(
            $first->submissionId,
            data_get($consent->meta, 'forms.submission_id'),
        );
        $this->assertSame(
            'email_marketing_consent',
            data_get($consent->meta, 'forms.field'),
        );
        $this->assertEqualsCanonicalizing([
            'interest:music',
            'interest:vip',
        ], ContactTag::query()
            ->where('contact_id', $contact->getKey())
            ->pluck('tag')
            ->all());

        $gate = app(MessageGate::class);

        foreach ([
            'artist_updates',
            'broadcast',
            'campaign',
            'webinar_nurture',
            'future_marketing_scope',
        ] as $scope) {
            $this->assertTrue(
                $gate->canSend(
                    contact: $contact,
                    channel: 'email',
                    purpose: 'marketing',
                    scope: $scope,
                ),
                "Expected broad email marketing consent to allow scope [{$scope}].",
            );
        }

        $this->assertFalse(
            $gate->canSend(
                contact: $contact,
                channel: 'sms',
                purpose: 'marketing',
                scope: 'artist_updates',
            ),
        );

        $replay = app(CreateFormSubmissionAction::class)->handle(
            $this->artistInput(
                externalId: '8de2189c-7bba-42ce-bc61-cfdd70c936f8',
                emailConsent: true,
                smsConsent: false,
            ),
        );

        $this->assertTrue($replay->replayed);
        $this->assertSame($first->submissionId, $replay->submissionId);
        $this->assertSame(1, MessageConsent::query()->count());
    }

    public function test_channel_purpose_revocation_blocks_all_marketing_scopes_for_that_channel_only(): void
    {
        $this->publishedArtistForm();

        $result = app(CreateFormSubmissionAction::class)->handle(
            $this->artistInput(
                externalId: '45e29859-4971-48f6-9989-69f38951a475',
                emailConsent: true,
                smsConsent: true,
            ),
        );

        $contact = Contact::query()->findOrFail($result->contactId);

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            source: 'test',
        );

        app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'reason' => 'unsubscribe',
            'source' => 'test',
        ]);

        $gate = app(MessageGate::class);

        foreach ([
            'artist_updates',
            'broadcast',
            'campaign',
            'webinar_nurture',
        ] as $scope) {
            $this->assertFalse(
                $gate->canSend(
                    contact: $contact,
                    channel: 'email',
                    purpose: 'marketing',
                    scope: $scope,
                ),
                "Expected email marketing revocation to block scope [{$scope}].",
            );
        }

        $this->assertTrue(
            $gate->canSend(
                contact: $contact,
                channel: 'sms',
                purpose: 'marketing',
                scope: 'artist_updates',
            ),
        );
        $this->assertTrue(
            $gate->canSend(
                contact: $contact,
                channel: 'email',
                purpose: 'transactional',
                scope: 'webinar',
            ),
        );
    }

    public function test_messaging_enabled_form_consent_does_not_require_channel_purpose_domain_mapping(): void
    {
        config()->set('messaging.consent.channel_purpose_domains', []);
        $this->app->forgetInstance(FormSubmissionConsentBridge::class);
        $this->publishedArtistForm();

        $result = app(CreateFormSubmissionAction::class)->handle(
            $this->artistInput(
                externalId: '28153b02-2a94-420a-b202-5653c04e0a36',
                emailConsent: true,
                smsConsent: false,
            ),
        );

        $this->assertNotNull($result->contactId);
        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertSame(1, MessageConsent::query()->count());
        $this->assertDatabaseHas('message_consents', [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'forms',
            'source' => 'forms_submission',
        ]);
    }

    public function test_setup_validation_accepts_consent_without_channel_purpose_domain_mapping(): void
    {
        config()->set('messaging.consent.channel_purpose_domains', []);
        $this->app->forgetInstance(FormSubmissionConsentBridge::class);
        $this->publishedArtistForm();

        $finding = collect(
            app(FormsSetupValidationContributor::class)->findings(),
        )->first(
            fn ($finding): bool =>
                $finding->code === 'forms.runtime.submission_consent_invalid',
        );

        $this->assertNull($finding);
    }

    public function test_forms_only_runtime_records_the_form_answer_without_requiring_messaging(): void
    {
        config()->set('modules.enabled', [
            'core',
            'forms',
        ]);
        $this->app->forgetInstance(FormSubmissionConsentBridge::class);
        $this->publishedArtistForm();

        $result = app(CreateFormSubmissionAction::class)->handle(
            $this->artistInput(
                externalId: '1d184665-da37-4c42-b463-496872289328',
                emailConsent: true,
                smsConsent: false,
            ),
        );

        $submission = FormSubmission::query()->findOrFail($result->submissionId);

        $this->assertTrue($submission->payload['email_marketing_consent']);
        $this->assertSame(0, MessageConsent::query()->count());
    }

    private function publishedArtistForm(): void
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => 'artist_updates',
            'name' => 'Artist Updates',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Artist Updates',
            'schema' => $this->artistSchema(),
            'rules' => [],
            'settings' => $this->artistSettings(),
        ]);
        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();
    }

    private function artistInput(
        string $externalId,
        bool $emailConsent,
        bool $smsConsent,
    ): FormSubmissionInput {
        return new FormSubmissionInput(
            formKey: 'artist_updates',
            values: [
                'email' => 'fan@example.com',
                'phone' => '555-555-0100',
                'interests' => ['music', 'vip'],
                'email_marketing_consent' => $emailConsent,
                'sms_marketing_consent' => $smsConsent,
            ],
            source: 'engage_sites',
            provider: 'engage_sites',
            externalId: $externalId,
            ipAddress: '203.0.113.10',
            userAgent: 'Forms messaging bridge test',
            publicOnly: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artistSchema(): array
    {
        return [
            'sections' => [[
                'key' => 'contact',
                'label' => 'Contact',
                'fields' => [
                    [
                        'key' => 'email',
                        'label' => 'Email',
                        'type' => 'email',
                        'required' => true,
                    ],
                    [
                        'key' => 'phone',
                        'label' => 'Phone',
                        'type' => 'tel',
                        'required' => false,
                    ],
                    [
                        'key' => 'interests',
                        'label' => 'Interests',
                        'type' => 'checkboxes',
                        'required' => false,
                        'options' => [
                            ['value' => 'music', 'label' => 'Music'],
                            ['value' => 'tour', 'label' => 'Tour'],
                            ['value' => 'vip', 'label' => 'VIP'],
                        ],
                    ],
                    [
                        'key' => 'email_marketing_consent',
                        'label' => 'Email updates',
                        'type' => 'boolean',
                        'required' => false,
                    ],
                    [
                        'key' => 'sms_marketing_consent',
                        'label' => 'SMS updates',
                        'type' => 'boolean',
                        'required' => false,
                    ],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artistSettings(): array
    {
        return [
            'submission' => [
                'contact' => [
                    'fields' => [
                        'email' => 'email',
                        'phone' => 'phone',
                    ],
                    'source' => 'engage_sites',
                    'subsource' => 'artist_updates',
                ],
                'tags' => [[
                    'field' => 'interests',
                    'values' => [
                        'music' => 'interest:music',
                        'tour' => 'interest:tour',
                        'vip' => 'interest:vip',
                    ],
                ]],
                'consents' => [
                    [
                        'field' => 'email_marketing_consent',
                        'channel' => 'email',
                        'purpose' => 'marketing',
                    ],
                    [
                        'field' => 'sms_marketing_consent',
                        'channel' => 'sms',
                        'purpose' => 'marketing',
                    ],
                ],
            ],
        ];
    }
}