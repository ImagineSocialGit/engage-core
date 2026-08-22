<?php

namespace Tests\Feature\Forms;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Forms\Actions\CreateFormSubmissionAction;
use App\Modules\Forms\Actions\SyncFormPresetsAction;
use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Exceptions\FormSubmissionValidationException;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmissionValue;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Presets\FormsPresetContributor;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Support\ModuleIntegrations\Forms\FormSubmissionConsentBridge;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetContributionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistUpdatesPresetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'artist-updates-test');
        config()->set('modules.enabled', [
            'core',
            'forms',
            'messaging',
        ]);
        config()->set('messaging.consent_domains', [
            'marketing' => [
                'topic' => 'marketing communications',
                'scopes' => [],
                'scope_prefixes' => [],
                'opt_in' => [],
            ],
        ]);
        config()->set('messaging.consent.channel_purpose_domains', [
            'email' => [
                'marketing' => 'marketing',
            ],
            'sms' => [
                'marketing' => 'marketing',
            ],
        ]);

        $this->app->forgetInstance(FormSubmissionConsentBridge::class);
    }

    public function test_forms_module_exposes_artist_updates_preset_without_selecting_it_in_the_base_basic_package(): void
    {
        $contributors = config('modules.modules.forms.preset_contributors', []);

        $this->assertContains(FormsPresetContributor::class, $contributors);
        $this->assertEquals(
            ['artist_updates' => ['artist_updates']],
            app(PresetContributionRegistry::class)->groups(PresetDomain::Forms),
        );

        $resolved = app(PresetCompositionResolver::class)->resolve(
            'basic',
            PresetDomain::Forms,
        );

        $this->assertSame([], $resolved->selectedGroups);
        $this->assertSame([], $resolved->definitionKeys);
    }

    public function test_artist_updates_preset_publishes_the_canonical_server_owned_integration_contract(): void
    {
        $this->selectArtistUpdatesPreset();
        $this->syncArtistUpdates();

        $definition = FormDefinition::query()
            ->where('key', 'artist_updates')
            ->sole();
        $version = $definition->currentVersion()->sole();

        $this->assertSame(FormDefinition::STATUS_ACTIVE, $definition->status);
        $this->assertTrue($definition->is_public);
        $this->assertSame(FormVersion::STATUS_PUBLISHED, $version->status);
        $this->assertEquals([
            'first_name',
            'last_name',
            'email',
            'phone',
            'postal_code',
            'interests',
            'email_marketing_consent',
            'sms_marketing_consent',
        ], collect(data_get($version->schema, 'sections.0.fields', []))
            ->pluck('key')
            ->all());
        $this->assertEquals([
            'email' => 'email',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'phone' => 'phone',
        ], data_get($version->settings, 'submission.contact.fields'));
        $this->assertSame(
            'engage_sites',
            data_get($version->settings, 'submission.contact.source'),
        );
        $this->assertSame(
            'artist_updates',
            data_get($version->settings, 'submission.contact.subsource'),
        );
        $this->assertEquals([
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
        ], data_get($version->settings, 'submission.consents'));
        $this->assertEquals([
            'required' => false,
            'providers' => ['turnstile'],
            'max_age_seconds' => 300,
            'action' => 'artist_updates',
            'require_hostname' => true,
        ], data_get($version->settings, 'submission.verification'));
    }

    public function test_artist_updates_submission_maps_contact_interests_and_broad_channel_purpose_consent(): void
    {
        $this->selectArtistUpdatesPreset();
        $this->syncArtistUpdates();

        $result = app(CreateFormSubmissionAction::class)->handle(
            $this->submissionInput(
                externalId: 'b3976ef7-afc8-4fae-adb8-4c6c0272f157',
                values: [
                    'first_name' => 'Taylor',
                    'last_name' => 'Fan',
                    'email' => 'fan@example.com',
                    'phone' => '555-555-0100',
                    'postal_code' => '37203',
                    'interests' => ['music', 'tour', 'vip'],
                    'email_marketing_consent' => true,
                    'sms_marketing_consent' => true,
                ],
            ),
        );

        $contact = Contact::query()->findOrFail($result->contactId);

        $this->assertSame('Taylor', $contact->first_name);
        $this->assertSame('Fan', $contact->last_name);
        $this->assertSame('fan@example.com', $contact->email);
        $this->assertSame('555-555-0100', $contact->phone);
        $this->assertSame('engage_sites', $contact->source);
        $this->assertSame('artist_updates', $contact->subsource);
        $this->assertNull(data_get($contact->meta, 'postal_code'));
        $this->assertEqualsCanonicalizing([
            'interest:general_updates',
            'interest:music',
            'interest:tour',
            'interest:vip',
        ], ContactTag::query()
            ->where('contact_id', $contact->getKey())
            ->pluck('tag')
            ->all());

        $postalCode = FormSubmissionValue::query()
            ->where('form_submission_id', $result->submissionId)
            ->where('field_key', 'postal_code')
            ->sole();

        $this->assertSame('37203', $postalCode->value_text);

        $consents = MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->orderBy('channel')
            ->get();

        $this->assertSame(2, $consents->count());
        $this->assertEqualsCanonicalizing([
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ], $consents
            ->pluck('channel')
            ->map(fn (MessageChannel $channel): string => $channel->value)
            ->all());

        foreach ($consents as $consent) {
            $this->assertSame(MessagePurpose::Marketing, $consent->purpose);
            $this->assertSame('marketing', $consent->scope);
            $this->assertSame('forms_submission', $consent->source);
        }
    }

    public function test_sms_marketing_acceptance_requires_a_phone_number(): void
    {
        $this->selectArtistUpdatesPreset();
        $this->syncArtistUpdates();

        try {
            app(CreateFormSubmissionAction::class)->handle(
                $this->submissionInput(
                    externalId: '8d918951-5279-4f11-be65-2789fe50705b',
                    values: [
                        'email' => 'fan@example.com',
                        'email_marketing_consent' => true,
                        'sms_marketing_consent' => true,
                    ],
                ),
            );

            $this->fail('SMS marketing acceptance without a phone number should fail validation.');
        } catch (FormSubmissionValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }

        $this->assertDatabaseCount('message_consents', 0);
    }

    public function test_client_overlay_can_promote_artist_updates_verification_to_required_without_mutating_the_previous_version(): void
    {
        $this->selectArtistUpdatesPreset();
        $this->syncArtistUpdates();

        $first = FormVersion::query()
            ->where('version', 1)
            ->sole();

        $forms = config('presets.modules.forms.forms');
        $forms['definitions']['artist_updates']['settings']['submission']['verification']['required'] = true;
        config()->set('presets.modules.forms.forms', $forms);

        $this->syncArtistUpdates();

        $definition = FormDefinition::query()
            ->where('key', 'artist_updates')
            ->sole();
        $current = $definition->currentVersion()->sole();

        $this->assertSame(2, $current->version);
        $this->assertTrue(
            data_get($current->settings, 'submission.verification.required'),
        );
        $this->assertFalse(
            data_get($first->fresh()->settings, 'submission.verification.required'),
        );
    }

    private function selectArtistUpdatesPreset(): void
    {
        config()->set('presets.packages.artist_updates_test', [
            'name' => 'Artist Updates Test',
            'groups' => [
                'contact_statuses' => [],
                'tasks' => [],
                'campaigns' => [],
                'flow_routes' => [],
                'forms' => ['artist_updates'],
            ],
        ]);
    }

    private function syncArtistUpdates(): void
    {
        app(SyncFormPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'artist_updates_test',
                PresetDomain::Forms,
            ),
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function submissionInput(string $externalId, array $values): FormSubmissionInput
    {
        return new FormSubmissionInput(
            formKey: 'artist_updates',
            values: $values,
            source: 'engage_sites',
            provider: 'engage_sites',
            externalId: $externalId,
            publicOnly: true,
        );
    }
}