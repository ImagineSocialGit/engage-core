<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class WebinarRegistrationConsentHeaderPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_consent_header_content_and_styles_are_configuration_driven(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'consent-header-presentation',
        ]);

        view()->share('errors', new ViewErrorBag());

        $html = view('components.webinars.registration-form-modal', [
            'page' => [
                'form_card' => [
                    'title' => 'Save Your Seat',
                    'body' => 'Register below.',
                ],
                'consent_header' => [
                    'enabled' => true,
                    'title' => 'Configured header title',
                    'body' => 'Configured header body',
                    'items' => [
                        'Configured item one',
                        'Configured item two',
                    ],
                ],
                'sections' => [
                    'notifications' => [
                        'title' => 'Webinar Registration',
                    ],
                    'marketing' => [
                        'title' => 'Stay Connected',
                    ],
                ],
                'fields' => [
                    'first_name' => ['label' => 'First Name'],
                    'last_name' => ['label' => 'Last Name'],
                    'email' => ['label' => 'Email Address'],
                    'phone' => ['label' => 'Mobile Phone'],
                    'consent_messages' => [
                        'email' => ['label' => 'Webinar email'],
                    ],
                    'marketing_consent_messages' => [
                        'email' => ['label' => 'Marketing email'],
                    ],
                ],
                'legal_links' => [
                    'enabled' => false,
                    'links' => [],
                ],
                'submit' => [
                    'label' => 'Reserve My Spot',
                ],
            ],
            'tokens' => [],
            'style' => [
                'consent_header' => [
                    'wrapper' => 'consent-header-wrapper',
                    'title' => 'consent-header-title',
                    'body' => 'consent-header-body',
                    'list' => 'consent-header-list',
                    'item' => 'consent-header-item',
                    'icon' => 'consent-header-icon',
                ],
            ],
            'series' => $series,
            'webinarRegistrationChannels' => [
                'transactional' => ['email'],
                'marketing' => ['email'],
            ],
            'registrationPrefill' => [],
        ])->render();

        $this->assertStringContainsString('Configured header title', $html);
        $this->assertStringContainsString('Configured header body', $html);
        $this->assertStringContainsString('Configured item one', $html);
        $this->assertStringContainsString('Configured item two', $html);

        foreach ([
            'consent-header-wrapper',
            'consent-header-title',
            'consent-header-body',
            'consent-header-list',
            'consent-header-item',
            'consent-header-icon',
        ] as $class) {
            $this->assertStringContainsString($class, $html);
        }
    }

    public function test_consent_header_can_be_disabled(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'disabled-consent-header-presentation',
        ]);

        view()->share('errors', new ViewErrorBag());

        $page = [
            'form_card' => [
                'title' => 'Save Your Seat',
            ],
            'consent_header' => [
                'enabled' => false,
                'title' => 'This must not render',
            ],
            'sections' => [
                'notifications' => ['title' => 'Webinar Registration'],
                'marketing' => ['title' => 'Stay Connected'],
            ],
            'fields' => [
                'first_name' => ['label' => 'First Name'],
                'last_name' => ['label' => 'Last Name'],
                'email' => ['label' => 'Email Address'],
                'phone' => ['label' => 'Mobile Phone'],
                'consent_messages' => [
                    'email' => ['label' => 'Webinar email'],
                ],
                'marketing_consent_messages' => [
                    'email' => ['label' => 'Marketing email'],
                ],
            ],
            'legal_links' => [
                'enabled' => false,
                'links' => [],
            ],
            'submit' => [
                'label' => 'Reserve My Spot',
            ],
        ];

        $html = view('components.webinars.registration-form-modal', [
            'page' => $page,
            'tokens' => [],
            'style' => [],
            'series' => $series,
            'webinarRegistrationChannels' => [
                'transactional' => ['email'],
                'marketing' => ['email'],
            ],
            'registrationPrefill' => [],
        ])->render();

        $this->assertStringNotContainsString('This must not render', $html);
    }
}
