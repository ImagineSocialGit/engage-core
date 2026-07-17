<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarRegisterPageConfigTest extends TestCase
{
    public function test_register_content_resolves_independent_landing_and_registration_contracts(): void
    {
        Config::set('webinars.content', [
            'site_name' => 'Example Webinars',
        ]);

        Config::set('webinars.register.content', [
            'landing' => [
                'hero' => [
                    'title' => 'Base hero',
                    'body' => 'Base hero body.',
                ],
                'problem' => [
                    'heading' => 'Base problem',
                ],
            ],
            'registration' => [
                'consents' => [
                    'transactional' => ['email' => true, 'sms' => true],
                    'marketing' => ['email' => true, 'sms' => true],
                ],
                'consent_header' => [
                    'enabled' => true,
                    'body' => 'Base consent header.',
                ],
                'sections' => [
                    'notifications' => [
                        'title' => 'Base webinar updates',
                        'body' => 'Base webinar delivery copy.',
                    ],
                ],
                'fields' => [
                    'phone' => [
                        'helper' => 'Base phone helper.',
                    ],
                    'consent_messages' => [
                        'email' => [
                            'label' => 'Base webinar email',
                        ],
                    ],
                ],
                'form_card' => [
                    'title' => 'Base modal title',
                ],
                'submit' => [
                    'label' => 'Base submit',
                ],
                'legal_links' => [
                    'enabled' => true,
                    'links' => [
                        ['label' => 'Privacy Policy', 'url' => 'https://example.test/privacy'],
                    ],
                ],
            ],
        ]);

        Config::set('webinars.register.homebuyer-game-plan.content', [
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
                'consent_header' => [
                    'body' => 'Series consent header.',
                ],
                'sections' => [
                    'notifications' => [
                        'title' => 'Series webinar choices',
                    ],
                ],
                'form_card' => [
                    'title' => 'Series modal title',
                ],
                'submit' => [
                    'label' => 'Series submit',
                ],
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'homebuyer-game-plan',
            seriesMeta: [
                'public_page' => [
                    'landing' => [
                        'hero' => [
                            'body' => 'Database hero body.',
                        ],
                    ],
                    'registration' => [
                        'fields' => [
                            'phone' => [
                                'helper' => 'Database phone helper.',
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame('Example Webinars', data_get($content, 'landing.site_name'));
        $this->assertSame('Series hero', data_get($content, 'landing.hero.title'));
        $this->assertSame('Database hero body.', data_get($content, 'landing.hero.body'));
        $this->assertSame('Series problem', data_get($content, 'landing.problem.heading'));

        $this->assertSame([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => false, 'sms' => false],
        ], data_get($content, 'registration.consents'));
        $this->assertSame(
            'Series consent header.',
            data_get($content, 'registration.consent_header.body'),
        );
        $this->assertSame(
            'Series webinar choices',
            data_get($content, 'registration.sections.notifications.title'),
        );
        $this->assertSame(
            'Base webinar delivery copy.',
            data_get($content, 'registration.sections.notifications.body'),
        );
        $this->assertSame(
            'Database phone helper.',
            data_get($content, 'registration.fields.phone.helper'),
        );
        $this->assertSame(
            'Base webinar email',
            data_get($content, 'registration.fields.consent_messages.email.label'),
        );
        $this->assertSame(
            'Series modal title',
            data_get($content, 'registration.form_card.title'),
        );
        $this->assertSame(
            'Series submit',
            data_get($content, 'registration.submit.label'),
        );
        $this->assertTrue(data_get($content, 'registration.legal_links.enabled'));
    }

    public function test_legacy_flat_register_layers_are_partitioned_without_losing_client_customization(): void
    {
        Config::set('webinars.register.content', [
            'hero' => [
                'title' => 'Flat base hero',
            ],
            'consents' => [
                'transactional' => ['email' => true, 'sms' => true],
                'marketing' => ['email' => true, 'sms' => true],
            ],
            'consent_header' => [
                'body' => 'Flat base consent header.',
            ],
            'fields' => [
                'phone' => [
                    'helper' => 'Flat base helper.',
                ],
            ],
        ]);

        Config::set('webinars.register.legacy-series.content', [
            'hero' => [
                'title' => 'Flat series hero',
            ],
            'consents' => [
                'marketing' => ['email' => false, 'sms' => false],
            ],
            'consent_header' => [
                'body' => 'Flat series consent header.',
            ],
            'fields' => [
                'phone' => [
                    'helper' => 'Flat series helper.',
                ],
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'legacy-series',
        );

        $this->assertSame('Flat series hero', data_get($content, 'landing.hero.title'));
        $this->assertSame([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => false, 'sms' => false],
        ], data_get($content, 'registration.consents'));
        $this->assertSame(
            'Flat series consent header.',
            data_get($content, 'registration.consent_header.body'),
        );
        $this->assertSame('Flat series helper.', data_get($content, 'registration.fields.phone.helper'));
        $this->assertArrayNotHasKey('consents', $content['landing']);
        $this->assertArrayNotHasKey('consent_header', $content['landing']);
        $this->assertArrayNotHasKey('fields', $content['landing']);
        $this->assertArrayNotHasKey('hero', $content['registration']);
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
                'email' => [
                    'label' => 'Global email',
                ],
            ],
        ]);

        Config::set('webinars.notify-me.content', [
            'fields' => [
                'email' => [
                    'label' => 'Notify-me email',
                ],
            ],
        ]);

        Config::set('webinars.notify-me.future-series.content', [
            'fields' => [
                'email' => [
                    'label' => 'Series notify-me email',
                ],
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
}
