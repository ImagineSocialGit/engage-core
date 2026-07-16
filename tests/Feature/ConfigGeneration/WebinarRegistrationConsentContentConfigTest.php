<?php

namespace Tests\Feature\ConfigGeneration;

use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarRegistrationConsentContentConfigTest extends TestCase
{
    public function test_base_registration_defaults_resolve_to_a_renderable_registration_contract(): void
    {
        Config::set(
            'webinars.register.content',
            require base_path('config/webinars/register/content.php'),
        );

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'default-series',
        );

        $registration = $content['registration'];

        $this->assertSame(
            'Webinar Registration (Required)',
            data_get($registration, 'sections.notifications.title'),
        );
        $this->assertSame(
            'Stay Connected (Optional)',
            data_get($registration, 'sections.marketing.title'),
        );

        foreach ([
            'fields.first_name.label',
            'fields.last_name.label',
            'fields.email.label',
            'fields.phone.label',
            'fields.consent_messages.email.label',
            'fields.consent_messages.email.helper',
            'fields.consent_messages.sms.label',
            'fields.consent_messages.sms.disclosure',
            'fields.marketing_consent_messages.email.label',
            'fields.marketing_consent_messages.sms.label',
            'fields.marketing_consent_messages.sms.disclosure',
        ] as $path) {
            $this->assertNotSame('', trim((string) data_get($registration, $path)), $path);
        }
    }

    public function test_client_registration_configs_publish_the_requested_consent_copy(): void
    {
        foreach ([
            'slam-dunk-crm' => [
                'config_path' => 'client/slam-dunk-crm/config/webinars/register/content.php',
                'sender_name' => 'Slam Dunk Home Loans',
            ],
            'rob-the-mortgage-coach' => [
                'config_path' => 'client/rob-the-mortgage-coach/config/webinars/register/content.php',
                'sender_name' => 'Rob The Mortgage Coach',
            ],
        ] as $label => $expected) {
            $content = require base_path($expected['config_path']);
            $registration = $content['registration'];
            $senderName = $expected['sender_name'];

            $this->assertSame(
                'Webinar Registration (Required)',
                data_get($registration, 'sections.notifications.title'),
                $label,
            );
            $this->assertSame(
                'Stay Connected (Optional)',
                data_get($registration, 'sections.marketing.title'),
                $label,
            );
            $this->assertSame(
                'Email me my webinar confirmation, Zoom link, reminders, replay, and webinar-related updates.',
                data_get($registration, 'fields.consent_messages.email.label'),
                $label,
            );
            $this->assertSame(
                'You may unsubscribe at any time.',
                data_get($registration, 'fields.consent_messages.email.helper'),
                $label,
            );
            $this->assertSame(
                'Text me webinar reminders and access information. (Optional)',
                data_get($registration, 'fields.consent_messages.sms.label'),
                $label,
            );
            $this->assertSame(
                'By checking this box, you agree to receive automated text messages related to this webinar. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out.',
                data_get($registration, 'fields.consent_messages.sms.disclosure'),
                $label,
            );
            $this->assertSame(
                "Send me marketing emails for future webinars, homebuying tips, loan program updates, and educational content from {$senderName}.",
                data_get($registration, 'fields.marketing_consent_messages.email.label'),
                $label,
            );
            $this->assertSame(
                "Send me marketing texts for future webinars, mortgage updates, and educational content from {$senderName}.",
                data_get($registration, 'fields.marketing_consent_messages.sms.label'),
                $label,
            );
            $this->assertSame(
                'Message frequency varies. Msg & data rates may apply. Reply STOP to opt out.',
                data_get($registration, 'fields.marketing_consent_messages.sms.disclosure'),
                $label,
            );
        }
    }

    public function test_client_registration_configs_are_not_required_to_duplicate_the_base_shape(): void
    {
        Config::set('webinars.register.content', [
            'registration' => [
                'sections' => [
                    'notifications' => [
                        'title' => 'Base webinar delivery',
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
            ],
        ]);

        Config::set('webinars.register.client-series.content', [
            'registration' => [
                'sections' => [
                    'notifications' => [
                        'title' => 'Client delivery choices',
                    ],
                ],
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'client-series',
        );

        $this->assertSame(
            'Client delivery choices',
            data_get($content, 'registration.sections.notifications.title'),
        );
        $this->assertSame(
            'Base webinar email',
            data_get($content, 'registration.fields.consent_messages.email.label'),
        );
        $this->assertSame(
            'Base phone helper.',
            data_get($content, 'registration.fields.phone.helper'),
        );
    }

    public function test_slam_dunk_uses_terms_of_service_label(): void
    {
        $content = require base_path('client/slam-dunk-crm/config/webinars/register/content.php');

        $this->assertSame(
            'Terms of Service',
            data_get($content, 'registration.legal_links.links.0.label'),
        );
        $this->assertSame(
            'Privacy Policy',
            data_get($content, 'registration.legal_links.links.1.label'),
        );
    }

    public function test_rob_registration_config_does_not_publish_placeholder_legal_links(): void
    {
        $content = require base_path('client/rob-the-mortgage-coach/config/webinars/register/content.php');

        $this->assertFalse(data_get($content, 'registration.legal_links.enabled'));
        $this->assertSame([], data_get($content, 'registration.legal_links.links'));
    }

}
