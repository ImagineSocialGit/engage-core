<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarRegisterPageDefinitionValidator;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class WebinarRegistrationDisclosurePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_referenced_disclosures_render_once_and_describe_each_control(): void
    {
        $html = $this->renderModal($this->referencedDisclosurePage());

        $this->assertStringContainsString(
            'aria-describedby="webinar-registration-sms-terms-disclosure"',
            $html,
        );
        $this->assertStringContainsString(
            'aria-describedby="webinar-registration-sms-terms-disclosure webinar-registration-unsubscribe-disclosure"',
            $html,
        );

        $this->assertStringContainsString(
            'id="webinar-registration-sms-terms-disclosure"',
            $html,
        );
        $this->assertStringContainsString(
            'id="webinar-registration-unsubscribe-disclosure"',
            $html,
        );

        $this->assertSame(
            1,
            substr_count($html, 'Configured shared text-message terms.'),
        );
        $this->assertSame(
            1,
            substr_count($html, 'You may unsubscribe at any time.'),
        );

        $this->assertStringContainsString('>1</sup>', $html);
        $this->assertStringContainsString('>2</sup>', $html);

        $this->assertStringContainsString(
            'href="https://example.test/terms"',
            $html,
        );
        $this->assertStringContainsString(
            'href="https://example.test/privacy"',
            $html,
        );
        $this->assertStringContainsString('Configured terms', $html);
        $this->assertStringContainsString('Configured privacy', $html);
    }

    public function test_disclosure_component_supports_arbitrary_markers_labels_and_styles(): void
    {
        $html = view('components.ui.disclosures', [
            'items' => [
                'custom_notice' => [
                    'marker' => '†',
                    'label' => 'Configured label:',
                    'text' => 'Configured reusable disclosure.',
                ],
                'empty_notice' => [
                    'marker' => '‡',
                    'text' => '   ',
                ],
            ],
            'idPrefix' => 'example-form',
            'style' => [
                'wrapper' => 'configured-wrapper',
                'item' => 'configured-item',
                'marker' => 'configured-marker',
                'label' => 'configured-label',
                'text' => 'configured-text',
            ],
        ])->render();

        $this->assertStringContainsString('configured-wrapper', $html);
        $this->assertStringContainsString('configured-item', $html);
        $this->assertStringContainsString('configured-marker', $html);
        $this->assertStringContainsString('configured-label', $html);
        $this->assertStringContainsString('configured-text', $html);
        $this->assertStringContainsString(
            'id="example-form-custom-notice-disclosure"',
            $html,
        );
        $this->assertStringContainsString('Configured label:', $html);
        $this->assertStringContainsString(
            'Configured reusable disclosure.',
            $html,
        );
        $this->assertStringNotContainsString('empty-notice', $html);
    }

    public function test_registration_config_classifies_disclosures_as_form_content_and_replaces_reference_lists_atomically(): void
    {
        Config::set('webinars.content', [
            'disclosures' => [
                'items' => [
                    'base_notice' => [
                        'text' => 'Base notice.',
                    ],
                ],
            ],
            'fields' => [
                'consent_messages' => [
                    'sms' => [
                        'disclosure_refs' => [
                            'base_notice',
                            'shared_notice',
                        ],
                    ],
                ],
            ],
        ]);
        Config::set('webinars.register.content', [
            'registration' => [
                'disclosures' => [
                    'items' => [
                        'shared_notice' => [
                            'text' => 'Shared notice.',
                        ],
                        'replacement_notice' => [
                            'text' => 'Replacement notice.',
                        ],
                    ],
                ],
                'fields' => [
                    'consent_messages' => [
                        'sms' => [
                            'disclosure_refs' => [
                                'replacement_notice',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        Config::set('webinars.register.disclosure-audit.content', []);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'disclosure-audit',
        );

        $this->assertArrayNotHasKey('disclosures', $content['landing']);
        $this->assertSame(
            [
                'base_notice',
                'shared_notice',
                'replacement_notice',
            ],
            array_keys(data_get($content, 'registration.disclosures.items')),
        );
        $this->assertSame(
            ['replacement_notice'],
            data_get(
                $content,
                'registration.fields.consent_messages.sms.disclosure_refs',
            ),
        );
    }

    public function test_disclosure_validation_accepts_valid_definitions_and_reports_malformed_or_unknown_references(): void
    {
        $validator = app(WebinarRegisterPageDefinitionValidator::class);
        $validDefinition = [
            'landing' => [],
            'registration' => $this->referencedDisclosurePage(),
        ];

        $this->assertSame(
            [],
            $validator->validateResolvedDefinition(
                $validDefinition,
                'webinars.register.valid.content',
            ),
        );

        $invalidDefinition = $validDefinition;
        $invalidDefinition['registration']['disclosures']['items']['Bad Key'] = [
            'marker' => [],
            'text' => '',
        ];
        $invalidDefinition['registration']['fields']['consent_messages']['sms']['disclosure_refs'] = [
            'missing_notice',
            'missing_notice',
        ];
        $invalidDefinition['registration']['fields']['consent_messages']['email']['disclosure'] = 'Legacy inline disclosure.';

        $violations = $validator->validateResolvedDefinition(
            $invalidDefinition,
            'webinars.register.invalid.content',
        );
        $codes = array_column($violations, 'code');

        $this->assertContains(
            'webinars.register_page.disclosure_definition_invalid',
            $codes,
        );
        $this->assertContains(
            'webinars.register_page.disclosure_reference_invalid',
            $codes,
        );
        $this->assertContains(
            'webinars.register_page.inline_disclosure_unsupported',
            $codes,
        );
        $this->assertContains(
            'webinars.register.invalid.content.registration.fields.consent_messages.sms.disclosure_refs.0',
            array_column($violations, 'path'),
        );
        $this->assertContains(
            'webinars.register.invalid.content.registration.fields.consent_messages.sms.disclosure_refs.1',
            array_column($violations, 'path'),
        );
        $this->assertContains(
            'webinars.register.invalid.content.registration.fields.consent_messages.email.disclosure',
            array_column($violations, 'path'),
        );
    }

    /**
     * @param array<string, mixed> $page
     */
    private function renderModal(array $page): string
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'disclosure-presentation-'.bin2hex(random_bytes(4)),
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
        ]);

        view()->share('errors', new ViewErrorBag());

        return view('components.webinars.registration-form-modal', [
            'page' => $page,
            'tokens' => [],
            'style' => [],
            'series' => $series,
            'webinar' => $webinar,
            'webinarRegistrationChannels' => [
                'transactional' => ['email', 'sms'],
                'marketing' => ['email', 'sms'],
            ],
            'registrationPrefill' => [],
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function referencedDisclosurePage(): array
    {
        return [
            'form_card' => [
                'title' => 'Configured modal title',
                'body' => 'Configured modal body.',
            ],
            'questions' => [],
            'consent_header' => [
                'enabled' => false,
            ],
            'consents' => [
                'transactional' => [
                    'email' => true,
                    'sms' => true,
                    'order' => ['sms', 'email'],
                    'required_channels' => ['email'],
                ],
                'marketing' => [
                    'email' => true,
                    'sms' => true,
                    'combined' => true,
                ],
            ],
            'sections' => [
                'notifications' => [
                    'title' => 'Configured transactional section',
                    'body' => 'Configured transactional section body.',
                ],
                'marketing' => [
                    'title' => 'Configured marketing section',
                ],
            ],
            'disclosures' => [
                'items' => [
                    'sms_terms' => [
                        'marker' => '1',
                        'text' => 'Configured shared text-message terms.',
                    ],
                    'unsubscribe' => [
                        'marker' => '2',
                        'text' => 'You may unsubscribe at any time.',
                    ],
                ],
            ],
            'fields' => [
                'first_name' => ['label' => 'First Name'],
                'last_name' => ['label' => 'Last Name'],
                'email' => ['label' => 'Email Address'],
                'phone' => [
                    'label' => 'Mobile Phone',
                    'helper' => 'Required to receive SMS.',
                ],
                'consent_messages' => [
                    'email' => [
                        'label' => 'Configured transactional email label.',
                        'disclosure_refs' => [
                            'unsubscribe',
                        ],
                    ],
                    'sms' => [
                        'label' => 'Configured transactional SMS label.',
                        'disclosure_refs' => [
                            'sms_terms',
                        ],
                    ],
                ],
                'marketing_consent_messages' => [
                    'combined' => [
                        'label' => 'Configured combined marketing label.',
                        'disclosure_refs' => [
                            'sms_terms',
                            'unsubscribe',
                        ],
                    ],
                    'email' => [
                        'label' => 'Configured marketing email label.',
                    ],
                    'sms' => [
                        'label' => 'Configured marketing SMS label.',
                    ],
                ],
            ],
            'legal_links' => [
                'enabled' => true,
                'intro' => 'Configured legal introduction',
                'links' => [
                    [
                        'label' => 'Configured terms',
                        'url' => 'https://example.test/terms',
                    ],
                    [
                        'label' => 'Configured privacy',
                        'url' => 'https://example.test/privacy',
                    ],
                ],
            ],
            'submit' => [
                'label' => 'Configured submit label',
            ],
        ];
    }
}