<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarRegisterPageConfigTest extends TestCase
{
    public function test_register_content_applies_only_permitted_series_sections(): void
    {
        Config::set('webinars.content', [
            'site_name' => 'Example Webinars',
        ]);

        Config::set('webinars.register.content', [
            'series_overrides' => [
                'landing' => [
                    'hero' => true,
                    'problem' => true,
                ],
                'registration' => [
                    'questions_section' => true,
                    'questions' => true,
                ],
            ],
            'landing' => [
                'hero' => [
                    'title' => 'Shared hero',
                    'body' => 'Shared hero body.',
                ],
                'problem' => [
                    'heading' => 'Shared problem',
                ],
            ],
            'registration' => [
                'consents' => [
                    'transactional' => ['email' => true, 'sms' => true],
                    'marketing' => ['email' => true, 'sms' => true],
                ],
                'fields' => [
                    'phone' => [
                        'helper' => 'Shared phone helper.',
                    ],
                ],
                'form_card' => [
                    'title' => 'Shared modal title',
                ],
                'questions' => [
                    $this->questionDefinition('shared_question', 'Shared question?'),
                ],
            ],
        ]);

        Config::set('webinars.register.configured-series.content', [
            'landing' => [
                'hero' => [
                    'title' => 'Series hero',
                ],
                'problem' => [
                    'heading' => 'Series problem',
                ],
            ],
            'registration' => [
                'consents' => [
                    'marketing' => ['email' => false, 'sms' => false],
                ],
                'form_card' => [
                    'title' => 'Series modal title',
                ],
                'questions' => [
                    $this->questionDefinition('series_question', 'Series question?'),
                ],
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'configured-series',
            seriesMeta: [
                'public_page' => [
                    'landing' => [
                        'hero' => [
                            'body' => 'Metadata hero body.',
                        ],
                    ],
                    'registration' => [
                        'fields' => [
                            'phone' => [
                                'helper' => 'Metadata phone helper.',
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame('Example Webinars', data_get($content, 'landing.site_name'));
        $this->assertSame('Series hero', data_get($content, 'landing.hero.title'));
        $this->assertSame('Metadata hero body.', data_get($content, 'landing.hero.body'));
        $this->assertSame('Series problem', data_get($content, 'landing.problem.heading'));
        $this->assertArrayNotHasKey('series_overrides', $content['landing']);

        $this->assertSame([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => true, 'sms' => true],
        ], data_get($content, 'registration.consents'));
        $this->assertSame(
            'Shared phone helper.',
            data_get($content, 'registration.fields.phone.helper'),
        );
        $this->assertSame(
            'Shared modal title',
            data_get($content, 'registration.form_card.title'),
        );
        $this->assertSame(
            ['series_question'],
            array_column(data_get($content, 'registration.questions'), 'key'),
        );
    }

    public function test_legacy_flat_series_content_obeys_the_same_override_policy(): void
    {
        Config::set('webinars.register.content', [
            'series_overrides' => [
                'landing' => [
                    'hero' => true,
                ],
                'registration' => [
                    'questions_section' => true,
                    'questions' => true,
                ],
            ],
            'hero' => [
                'title' => 'Shared flat hero',
            ],
            'consents' => [
                'transactional' => ['email' => true, 'sms' => true],
                'marketing' => ['email' => true, 'sms' => true],
            ],
            'questions' => [
                $this->questionDefinition('shared_question', 'Shared question?'),
            ],
        ]);

        Config::set('webinars.register.legacy-series.content', [
            'hero' => [
                'title' => 'Series flat hero',
            ],
            'consents' => [
                'marketing' => ['email' => false, 'sms' => false],
            ],
            'questions' => [
                $this->questionDefinition('series_question', 'Series question?'),
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'legacy-series',
        );

        $this->assertSame('Series flat hero', data_get($content, 'landing.hero.title'));
        $this->assertSame([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => true, 'sms' => true],
        ], data_get($content, 'registration.consents'));
        $this->assertSame(
            ['series_question'],
            array_column(data_get($content, 'registration.questions'), 'key'),
        );
        $this->assertArrayNotHasKey('consents', $content['landing']);
        $this->assertArrayNotHasKey('hero', $content['registration']);
    }

    public function test_unauthorized_series_paths_use_normalized_ownership_buckets(): void
    {
        Config::set('webinars.register.content.series_overrides', [
            'landing' => [
                'hero' => true,
            ],
            'registration' => [
                'questions' => true,
            ],
        ]);

        $paths = app(WebinarRegisterPageConfig::class)
            ->unauthorizedSeriesOverridePaths([
                'hero' => [
                    'title' => 'Allowed hero',
                ],
                'header' => [
                    'primary_link' => ['label' => 'Protected header'],
                ],
                'registration' => [
                    'questions' => [],
                    'fields' => [
                        'email' => ['label' => 'Protected email label'],
                    ],
                ],
            ]);

        $this->assertSame([
            'landing.header',
            'registration.fields',
        ], $paths);
    }

    public function test_atomic_series_lists_replace_shared_lists_after_policy_filtering(): void
    {
        Config::set('webinars.register.content', [
            'series_overrides' => [
                'landing' => [
                    'instructor' => true,
                    'trust' => true,
                ],
                'registration' => [],
            ],
            'instructor' => [
                'body' => ['Shared paragraph one.', 'Shared paragraph two.'],
                'credibility' => ['Shared credential one', 'Shared credential two'],
            ],
            'trust' => [
                'reviews' => [
                    ['name' => 'Shared one'],
                    ['name' => 'Shared two'],
                ],
                'stories' => [
                    ['key' => 'shared_story'],
                ],
            ],
        ]);

        Config::set('webinars.register.atomic-series.content', [
            'instructor' => [
                'body' => ['Series paragraph.'],
                'credibility' => ['Series credential'],
            ],
            'trust' => [
                'reviews' => [],
                'stories' => [
                    ['key' => 'series_story'],
                ],
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'atomic-series',
        );

        $this->assertSame(
            ['Series paragraph.'],
            data_get($content, 'landing.instructor.body'),
        );
        $this->assertSame(
            ['Series credential'],
            data_get($content, 'landing.instructor.credibility'),
        );
        $this->assertSame([], data_get($content, 'landing.trust.reviews'));
        $this->assertSame(
            [['key' => 'series_story']],
            data_get($content, 'landing.trust.stories'),
        );
    }

    public function test_register_style_separates_landing_and_registration_styles_and_shares_tokens(): void
    {
        Config::set('webinars.style', [
            'tokens' => [
                'field_error' => 'base-error',
                'hero_title' => 'base-hero-title',
            ],
            'components' => [
                'checkbox' => [
                    'input' => 'base-checkbox',
                ],
            ],
        ]);

        Config::set('webinars.register.style', [
            'hero' => [
                'section' => 'base-hero',
            ],
            'registration' => [
                'form_card' => [
                    'class' => 'base-form-card',
                ],
                'consent_header' => [
                    'body' => 'base-consent-header-body',
                ],
            ],
        ]);

        Config::set('webinars.register.custom-series.style', [
            'hero' => [
                'section' => 'series-hero',
            ],
            'consent_header' => [
                'body' => 'series-consent-header-body',
            ],
            'legal_links' => [
                'link' => 'series-legal-link',
            ],
        ]);

        $style = app(WebinarRegisterPageConfig::class)->style(
            page: 'register',
            seriesSlug: 'custom-series',
        );

        $this->assertSame('series-hero', data_get($style, 'landing.hero.section'));
        $this->assertSame('base-hero-title', data_get($style, 'landing.tokens.hero_title'));
        $this->assertSame('base-form-card', data_get($style, 'registration.form_card.class'));
        $this->assertSame(
            'series-consent-header-body',
            data_get($style, 'registration.consent_header.body'),
        );
        $this->assertSame('series-legal-link', data_get($style, 'registration.legal_links.link'));
        $this->assertSame('base-error', data_get($style, 'registration.tokens.field_error'));
        $this->assertSame('base-checkbox', data_get($style, 'registration.components.checkbox.input'));
        $this->assertArrayNotHasKey('consent_header', $style['landing']);
        $this->assertArrayNotHasKey('hero', $style['registration']);
    }

    public function test_non_register_pages_keep_unrestricted_flat_merge_behavior(): void
    {
        Config::set('webinars.content', [
            'fields' => [
                'email' => ['label' => 'Shared email'],
            ],
        ]);
        Config::set('webinars.notify-me.content', [
            'fields' => [
                'email' => ['label' => 'Notify-me email'],
            ],
        ]);
        Config::set('webinars.notify-me.future-series.content', [
            'fields' => [
                'email' => ['label' => 'Series notify-me email'],
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'notify-me',
            seriesSlug: 'future-series',
        );

        $this->assertSame('Series notify-me email', data_get($content, 'fields.email.label'));
        $this->assertArrayNotHasKey('landing', $content);
        $this->assertArrayNotHasKey('registration', $content);
    }

    /**
     * @return array<string, mixed>
     */
    private function questionDefinition(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'select',
            'required' => true,
            'options' => [
                ['key' => 'option_one', 'label' => 'Option One'],
                ['key' => 'other', 'label' => 'Other'],
            ],
            'other' => [
                'option_key' => 'other',
                'required' => true,
                'max_length' => 500,
            ],
        ];
    }
}