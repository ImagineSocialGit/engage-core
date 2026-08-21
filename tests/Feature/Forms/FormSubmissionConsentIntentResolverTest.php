<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Services\FormSubmissionConsentIntentResolver;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormSubmissionConsentIntentResolverTest extends TestCase
{
    public function test_channel_and_purpose_consent_mappings_resolve_without_scope_semantics(): void
    {
        $intents = app(FormSubmissionConsentIntentResolver::class)->resolve(
            $this->form([
                'submission' => [
                    'contact' => [
                        'fields' => ['email' => 'email'],
                    ],
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
            ]),
        );

        $this->assertCount(2, $intents);
        $this->assertSame('email_marketing_consent', $intents[0]->field);
        $this->assertSame('email', $intents[0]->channel);
        $this->assertSame('marketing', $intents[0]->purpose);
        $this->assertSame('sms_marketing_consent', $intents[1]->field);
        $this->assertSame('sms', $intents[1]->channel);
        $this->assertSame('marketing', $intents[1]->purpose);
    }

    #[DataProvider('invalidMappings')]
    public function test_invalid_consent_mappings_fail_closed(array $mapping, string $message): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);

        app(FormSubmissionConsentIntentResolver::class)->resolve(
            $this->form([
                'submission' => [
                    'contact' => [
                        'fields' => ['email' => 'email'],
                    ],
                    'consents' => [$mapping],
                ],
            ]),
        );
    }

    public static function invalidMappings(): array
    {
        return [
            'scope is not part of the Forms consent contract' => [
                [
                    'field' => 'email_marketing_consent',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'artist_updates',
                ],
                'contains unknown keys: scope',
            ],
            'field must be boolean-like' => [
                [
                    'field' => 'email',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                ],
                'must be a checkbox or boolean field',
            ],
            'field must exist' => [
                [
                    'field' => 'missing_consent',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                ],
                'references unknown form field [missing_consent]',
            ],
        ];
    }

    public function test_consent_mapping_requires_contact_mapping(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'settings.submission.consents requires settings.submission.contact',
        );

        app(FormSubmissionConsentIntentResolver::class)->resolve(
            $this->form([
                'submission' => [
                    'consents' => [[
                        'field' => 'email_marketing_consent',
                        'channel' => 'email',
                        'purpose' => 'marketing',
                    ]],
                ],
            ]),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function form(array $settings): PublishedForm
    {
        $fields = [
            [
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => true,
                'section_key' => 'contact',
                'section_label' => 'Contact',
                'sort_order' => 0,
            ],
            [
                'key' => 'email_marketing_consent',
                'label' => 'Email updates',
                'type' => 'boolean',
                'required' => false,
                'section_key' => 'contact',
                'section_label' => 'Contact',
                'sort_order' => 1,
            ],
            [
                'key' => 'sms_marketing_consent',
                'label' => 'SMS updates',
                'type' => 'boolean',
                'required' => false,
                'section_key' => 'contact',
                'section_label' => 'Contact',
                'sort_order' => 2,
            ],
        ];

        return new PublishedForm(
            definitionId: 1,
            versionId: 2,
            versionNumber: 1,
            key: 'artist_updates',
            name: 'Artist Updates',
            description: null,
            category: 'intake',
            isPublic: true,
            schema: [
                'sections' => [[
                    'key' => 'contact',
                    'label' => 'Contact',
                    'fields' => array_map(
                        static fn (array $field): array => array_intersect_key(
                            $field,
                            array_flip(['key', 'label', 'type', 'required']),
                        ),
                        $fields,
                    ),
                ]],
            ],
            rules: [],
            layout: [],
            settings: $settings,
            fields: $fields,
        );
    }
}