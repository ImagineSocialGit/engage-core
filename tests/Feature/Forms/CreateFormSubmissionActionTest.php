<?php

namespace Tests\Feature\Forms;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Forms\Actions\CreateFormSubmissionAction;
use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Exceptions\FormSubmissionReplayConflictException;
use App\Modules\Forms\Exceptions\FormSubmissionValidationException;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionValue;
use App\Modules\Forms\Models\FormVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateFormSubmissionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_pins_the_exact_version_and_persists_contact_values_tags_and_evidence(): void
    {
        [$definition, $version] = $this->publishedArtistForm();

        $result = app(CreateFormSubmissionAction::class)->handle(
            new FormSubmissionInput(
                formKey: 'artist_updates',
                values: [
                    'first_name' => '  Jeff ',
                    'last_name' => 'Yarnall',
                    'email' => ' JEFF@EXAMPLE.COM ',
                    'phone' => ' 555-555-0100 ',
                    'postal_code' => '60601',
                    'interests' => ['music', 'vip', 'music'],
                    'email_marketing_consent' => true,
                    'sms_marketing_consent' => false,
                ],
                source: 'engage_sites',
                provider: 'Engage_Sites',
                externalId: '2bb67eed-825a-4efa-9742-13ed172d83d2',
                meta: [
                    'consent' => [
                        'disclosure_key' => 'artist_updates',
                        'disclosure_version' => 1,
                    ],
                ],
                ipAddress: '203.0.113.10',
                userAgent: 'Engage Sites feature test',
                publicOnly: true,
            ),
        );

        $submission = FormSubmission::query()->findOrFail($result->submissionId);
        $contact = Contact::query()->findOrFail($result->contactId);

        $this->assertFalse($result->replayed);
        $this->assertSame((int) $definition->getKey(), $result->definitionId);
        $this->assertSame((int) $version->getKey(), $result->versionId);
        $this->assertSame(1, $result->versionNumber);
        $this->assertSame(FormSubmission::STATUS_SUBMITTED, $result->status);
        $this->assertSame((int) $version->getKey(), $submission->form_version_id);
        $this->assertSame('engage_sites', $submission->provider);
        $this->assertSame('engage_sites', $submission->source);
        $this->assertSame('203.0.113.10', $submission->ip_address);
        $this->assertSame('jeff@example.com', $submission->payload['email']);
        $this->assertSame(' JEFF@EXAMPLE.COM ', $submission->raw_payload['email']);
        $this->assertEquals(['music', 'vip'], $submission->payload['interests']);
        $this->assertSame(
            'artist_updates',
            $submission->meta['consent']['disclosure_key'],
        );
        $this->assertIsString(
            $submission->meta['_forms']['idempotency_fingerprint'],
        );

        $this->assertSame('Jeff', $contact->first_name);
        $this->assertSame('Yarnall', $contact->last_name);
        $this->assertSame('jeff@example.com', $contact->email);
        $this->assertSame('555-555-0100', $contact->phone);
        $this->assertSame('engage_sites', $contact->source);
        $this->assertSame('artist_updates', $contact->subsource);
        $this->assertEqualsCanonicalizing([
            'interest:music',
            'interest:vip',
        ], $contact->tags()->pluck('tag')->all());

        $email = $submission->values()->where('field_key', 'email')->sole();
        $interests = $submission->values()->where('field_key', 'interests')->sole();
        $emailConsent = $submission->values()
            ->where('field_key', 'email_marketing_consent')
            ->sole();

        $this->assertSame('Email', $email->field_label);
        $this->assertSame('email', $email->field_type);
        $this->assertSame('jeff@example.com', $email->value_text);
        $this->assertEquals(['music', 'vip'], $interests->value);
        $this->assertTrue($emailConsent->value_boolean);
    }

    public function test_submission_validation_rejects_unknown_missing_invalid_and_authored_rule_values_atomically(): void
    {
        $this->publishedArtistForm(rules: [
            'first_name' => ['max:3'],
        ]);

        try {
            app(CreateFormSubmissionAction::class)->handle(
                new FormSubmissionInput(
                    formKey: 'artist_updates',
                    values: [
                        'first_name' => 'Jeffrey',
                        'interests' => ['not_an_option'],
                        'email_marketing_consent' => 'maybe',
                        'contact_tags' => ['browser:authored'],
                    ],
                    source: 'engage_sites',
                    provider: 'engage_sites',
                    externalId: 'a123a390-5af3-43b6-8e1c-78bf304a8a54',
                ),
            );

            $this->fail('Expected submission validation to fail.');
        } catch (FormSubmissionValidationException $exception) {
            $this->assertEqualsCanonicalizing([
                '_submission',
                'email',
                'email_marketing_consent',
                'first_name',
                'interests',
            ], array_keys($exception->errors()));
        }

        $this->assertSame(0, FormSubmission::query()->count());
        $this->assertSame(0, FormSubmissionValue::query()->count());
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, ContactTag::query()->count());
    }

    public function test_all_supported_field_types_are_normalized_and_written_to_typed_value_columns(): void
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => 'type_contract',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'schema' => $this->allTypesSchema(),
            'settings' => [],
        ]);
        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        $result = app(CreateFormSubmissionAction::class)->handle(
            new FormSubmissionInput(
                formKey: 'type_contract',
                values: [
                    'text_value' => ' Text ',
                    'email_value' => 'USER@EXAMPLE.COM',
                    'tel_value' => ' 555-0100 ',
                    'url_value' => 'https://example.com/path',
                    'number_value' => '12.5000',
                    'textarea_value' => ' Long answer ',
                    'select_value' => 'alpha',
                    'radio_value' => 'beta',
                    'checkbox_value' => 'yes',
                    'checkboxes_value' => ['beta', 'alpha', 'beta'],
                    'boolean_value' => 0,
                    'date_value' => '2026-08-12',
                    'datetime_value' => '2026-08-12T13:30:00-05:00',
                    'hidden_value' => ' server-shaped-value ',
                ],
                publicOnly: true,
            ),
        );

        $submission = FormSubmission::query()->findOrFail($result->submissionId);

        $this->assertNull($result->contactId);
        $this->assertSame('Text', $submission->payload['text_value']);
        $this->assertSame('user@example.com', $submission->payload['email_value']);
        $this->assertSame(12.5, $submission->payload['number_value']);
        $this->assertEquals(
            ['beta', 'alpha'],
            $submission->payload['checkboxes_value'],
        );
        $this->assertFalse($submission->payload['boolean_value']);
        $this->assertSame(
            '2026-08-12T18:30:00+00:00',
            $submission->payload['datetime_value'],
        );

        $number = $submission->values()->where('field_key', 'number_value')->sole();
        $checkbox = $submission->values()->where('field_key', 'checkbox_value')->sole();
        $boolean = $submission->values()->where('field_key', 'boolean_value')->sole();
        $date = $submission->values()->where('field_key', 'date_value')->sole();
        $dateTime = $submission->values()->where('field_key', 'datetime_value')->sole();

        $this->assertSame('12.5000', $number->value_number);
        $this->assertTrue($checkbox->value_boolean);
        $this->assertFalse($boolean->value_boolean);
        $this->assertSame('2026-08-12', $date->value_date?->toDateString());
        $this->assertSame(
            '2026-08-12 18:30:00',
            $dateTime->value_datetime?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(14, $submission->values()->count());
    }

    public function test_external_retry_replays_existing_submission_even_after_the_current_form_version_changes(): void
    {
        [$definition, $version] = $this->publishedArtistForm();
        $input = $this->artistInput(
            externalId: '42be4acc-9369-44fc-8880-37e3562b21b9',
        );
        $action = app(CreateFormSubmissionAction::class);

        $first = $action->handle($input);

        $replacement = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 2,
            'schema' => $this->artistSchema(),
            'settings' => $this->artistSettings(),
        ]);
        $definition->forceFill([
            'current_form_version_id' => $replacement->getKey(),
        ])->save();

        $replay = $action->handle($input);

        $this->assertTrue($replay->replayed);
        $this->assertSame($first->submissionId, $replay->submissionId);
        $this->assertSame((int) $version->getKey(), $replay->versionId);
        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertSame(7, FormSubmissionValue::query()->count());
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(2, ContactTag::query()->count());
    }

    public function test_conflicting_external_replay_is_rejected_without_duplicate_side_effects(): void
    {
        $this->publishedArtistForm();
        $action = app(CreateFormSubmissionAction::class);
        $externalId = 'b98f7bc2-f533-41b1-a652-304812e46020';

        $action->handle($this->artistInput($externalId));

        $this->expectException(FormSubmissionReplayConflictException::class);

        try {
            $action->handle(new FormSubmissionInput(
                formKey: 'artist_updates',
                values: [
                    ...$this->artistValues(),
                    'first_name' => 'Different',
                ],
                source: 'engage_sites',
                provider: 'engage_sites',
                externalId: $externalId,
                publicOnly: true,
            ));
        } finally {
            $this->assertSame(1, FormSubmission::query()->count());
            $this->assertSame(1, Contact::query()->count());
            $this->assertSame(2, ContactTag::query()->count());
        }
    }

    public function test_contact_mapping_preserves_original_acquisition_source_and_adds_interest_tags(): void
    {
        $this->publishedArtistForm();
        $contact = Contact::factory()->create([
            'first_name' => 'Old',
            'last_name' => 'Name',
            'name' => 'Old Name',
            'email' => 'fan@example.com',
            'source' => 'referral',
            'subsource' => 'partner',
        ]);
        ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'interest:music',
        ]);

        app(CreateFormSubmissionAction::class)->handle(
            $this->artistInput('e3acff68-9065-4a1e-8669-ae1e77dd3a57'),
        );

        $contact->refresh();

        $this->assertSame('Jeff', $contact->first_name);
        $this->assertSame('Yarnall', $contact->last_name);
        $this->assertSame('referral', $contact->source);
        $this->assertSame('partner', $contact->subsource);
        $this->assertEqualsCanonicalizing([
            'interest:music',
            'interest:vip',
        ], $contact->tags()->pluck('tag')->all());
    }

    public function test_database_enforces_unique_non_null_external_submission_identity(): void
    {
        [, $version] = $this->publishedArtistForm();
        $input = $this->artistInput(
            '78d872f3-6d88-45d1-9d2f-051a3a5b2928',
        );
        app(CreateFormSubmissionAction::class)->handle($input);

        $this->expectException(QueryException::class);

        FormSubmission::query()->create([
            'form_definition_id' => $version->form_definition_id,
            'form_version_id' => $version->getKey(),
            'status' => FormSubmission::STATUS_SUBMITTED,
            'review_status' => FormSubmission::REVIEW_STATUS_PENDING,
            'submitted_at' => now(),
            'source' => 'engage_sites',
            'provider' => 'engage_sites',
            'external_id' => $input->externalId,
            'payload' => [],
            'raw_payload' => [],
            'meta' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{FormDefinition, FormVersion}
     */
    private function publishedArtistForm(array $rules = []): array
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
            'rules' => $rules,
            'settings' => $this->artistSettings(),
        ]);
        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return [$definition->refresh(), $version->refresh()];
    }

    private function artistInput(string $externalId): FormSubmissionInput
    {
        return new FormSubmissionInput(
            formKey: 'artist_updates',
            values: $this->artistValues(),
            source: 'engage_sites',
            provider: 'engage_sites',
            externalId: $externalId,
            publicOnly: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artistValues(): array
    {
        return [
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'fan@example.com',
            'phone' => '555-555-0100',
            'interests' => ['music', 'vip'],
            'email_marketing_consent' => true,
            'sms_marketing_consent' => false,
        ];
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
                        'key' => 'first_name',
                        'label' => 'First name',
                        'type' => 'text',
                        'required' => false,
                    ],
                    [
                        'key' => 'last_name',
                        'label' => 'Last name',
                        'type' => 'text',
                        'required' => false,
                    ],
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
                        'key' => 'postal_code',
                        'label' => 'ZIP/postal code',
                        'type' => 'text',
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
                        'first_name' => 'first_name',
                        'last_name' => 'last_name',
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
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allTypesSchema(): array
    {
        $options = [
            ['value' => 'alpha', 'label' => 'Alpha'],
            ['value' => 'beta', 'label' => 'Beta'],
        ];

        return [
            'sections' => [[
                'key' => 'types',
                'fields' => [
                    ['key' => 'text_value', 'label' => 'Text', 'type' => 'text'],
                    ['key' => 'email_value', 'label' => 'Email', 'type' => 'email'],
                    ['key' => 'tel_value', 'label' => 'Telephone', 'type' => 'tel'],
                    ['key' => 'url_value', 'label' => 'URL', 'type' => 'url'],
                    ['key' => 'number_value', 'label' => 'Number', 'type' => 'number'],
                    ['key' => 'textarea_value', 'label' => 'Textarea', 'type' => 'textarea'],
                    ['key' => 'select_value', 'label' => 'Select', 'type' => 'select', 'options' => $options],
                    ['key' => 'radio_value', 'label' => 'Radio', 'type' => 'radio', 'options' => $options],
                    ['key' => 'checkbox_value', 'label' => 'Checkbox', 'type' => 'checkbox'],
                    ['key' => 'checkboxes_value', 'label' => 'Checkboxes', 'type' => 'checkboxes', 'options' => $options],
                    ['key' => 'boolean_value', 'label' => 'Boolean', 'type' => 'boolean'],
                    ['key' => 'date_value', 'label' => 'Date', 'type' => 'date'],
                    ['key' => 'datetime_value', 'label' => 'Datetime', 'type' => 'datetime'],
                    ['key' => 'hidden_value', 'label' => 'Hidden', 'type' => 'hidden'],
                ],
            ]],
        ];
    }
}