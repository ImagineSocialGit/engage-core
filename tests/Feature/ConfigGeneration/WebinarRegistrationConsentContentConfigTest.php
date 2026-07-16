<?php

namespace Tests\Feature\ConfigGeneration;

use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarRegistrationConsentContentConfigTest extends TestCase
{
    public function test_base_registration_defaults_resolve_to_a_renderable_registration_contract(): void
    {
        Config::set('webinars.content', []);
        Config::set(
            'webinars.register.content',
            require base_path('config/webinars/register/content.php'),
        );
        Config::set('webinars.register.readiness-audit.content', []);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'readiness-audit',
        );

        $this->assertRenderableRegistrationContract(
            $content['registration'],
            'core registration defaults',
        );
    }

    public function test_every_client_registration_config_resolves_without_requiring_shared_language(): void
    {
        $baseContent = require base_path('config/webinars/register/content.php');
        $clientPaths = glob(base_path('client/*/config/webinars/register/content.php')) ?: [];

        $this->assertNotEmpty(
            $clientPaths,
            'No client webinar registration content configs were found.',
        );

        foreach ($clientPaths as $clientPath) {
            $clientContent = require $clientPath;

            $this->assertIsArray($clientContent, $clientPath);

            Config::set('webinars.content', []);
            Config::set(
                'webinars.register.content',
                array_replace_recursive($baseContent, $clientContent),
            );
            Config::set('webinars.register.readiness-audit.content', []);

            $resolved = app(WebinarRegisterPageConfig::class)->content(
                page: 'register',
                seriesSlug: 'readiness-audit',
            );

            $this->assertRenderableRegistrationContract(
                $resolved['registration'],
                $this->relativePath($clientPath),
            );
        }
    }

    public function test_every_series_registration_override_resolves_on_top_of_its_client_contract(): void
    {
        $baseContent = require base_path('config/webinars/register/content.php');
        $seriesPaths = glob(base_path('client/*/config/webinars/register/*/content.php')) ?: [];

        foreach ($seriesPaths as $seriesPath) {
            $registerDirectory = dirname(dirname($seriesPath));
            $clientContentPath = $registerDirectory.'/content.php';

            $this->assertFileExists($clientContentPath);

            $clientContent = require $clientContentPath;
            $seriesContent = require $seriesPath;

            $this->assertIsArray($clientContent, $clientContentPath);
            $this->assertIsArray($seriesContent, $seriesPath);

            Config::set('webinars.content', []);
            Config::set(
                'webinars.register.content',
                array_replace_recursive($baseContent, $clientContent),
            );
            Config::set('webinars.register.readiness-audit.content', $seriesContent);

            $resolved = app(WebinarRegisterPageConfig::class)->content(
                page: 'register',
                seriesSlug: 'readiness-audit',
            );

            $this->assertRenderableRegistrationContract(
                $resolved['registration'],
                $this->relativePath($seriesPath),
            );
        }
    }

    public function test_client_overrides_can_customize_copy_without_copying_the_base_shape(): void
    {
        Config::set('webinars.content', []);
        Config::set('webinars.register.content', [
            'registration' => [
                'sections' => [
                    'notifications' => [
                        'title' => 'Base delivery title',
                    ],
                    'marketing' => [
                        'title' => 'Base marketing title',
                    ],
                ],
                'fields' => [
                    'phone' => [
                        'helper' => 'Base phone helper.',
                    ],
                    'consent_messages' => [
                        'email' => [
                            'label' => 'Base webinar email label.',
                        ],
                    ],
                ],
            ],
        ]);

        Config::set('webinars.register.client-series.content', [
            'registration' => [
                'sections' => [
                    'notifications' => [
                        'title' => 'Client-specific delivery title',
                    ],
                ],
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'client-series',
        );

        $this->assertSame(
            'Client-specific delivery title',
            data_get($content, 'registration.sections.notifications.title'),
        );
        $this->assertSame(
            'Base marketing title',
            data_get($content, 'registration.sections.marketing.title'),
        );
        $this->assertSame(
            'Base webinar email label.',
            data_get($content, 'registration.fields.consent_messages.email.label'),
        );
        $this->assertSame(
            'Base phone helper.',
            data_get($content, 'registration.fields.phone.helper'),
        );
    }

    /**
     * @param array<string, mixed> $registration
     */
    private function assertRenderableRegistrationContract(
        array $registration,
        string $source,
    ): void {
        foreach ([
            'sections.notifications.title',
            'sections.marketing.title',
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
            $value = data_get($registration, $path);

            $this->assertIsString(
                $value,
                "{$source}: [{$path}] must resolve to a string.",
            );
            $this->assertNotSame(
                '',
                trim($value),
                "{$source}: [{$path}] must not be blank.",
            );
        }

        $consentHeader = data_get($registration, 'consent_header', []);

        $this->assertIsArray(
            $consentHeader,
            "{$source}: [consent_header] must resolve to an array.",
        );

        if (($consentHeader['enabled'] ?? true) === true) {
            $body = $consentHeader['body'] ?? null;

            $this->assertIsString(
                $body,
                "{$source}: enabled consent headers must provide [body].",
            );
            $this->assertNotSame(
                '',
                trim($body),
                "{$source}: enabled consent-header [body] must not be blank.",
            );
        }

        $this->assertSafeLegalLinks(
            data_get($registration, 'legal_links', []),
            $source,
        );
    }

    /**
     * @param mixed $legalLinks
     */
    private function assertSafeLegalLinks(mixed $legalLinks, string $source): void
    {
        $this->assertIsArray(
            $legalLinks,
            "{$source}: [legal_links] must resolve to an array.",
        );

        $links = $legalLinks['links'] ?? [];

        $this->assertIsArray(
            $links,
            "{$source}: [legal_links.links] must resolve to an array.",
        );

        if (($legalLinks['enabled'] ?? false) === true) {
            $this->assertNotEmpty(
                $links,
                "{$source}: enabled legal links must include at least one link.",
            );
        }

        foreach ($links as $index => $link) {
            $this->assertIsArray(
                $link,
                "{$source}: legal link [{$index}] must be an array.",
            );

            $label = $link['label'] ?? null;
            $url = $link['url'] ?? null;

            $this->assertIsString(
                $label,
                "{$source}: legal link [{$index}.label] must be a string.",
            );
            $this->assertNotSame(
                '',
                trim($label),
                "{$source}: legal link [{$index}.label] must not be blank.",
            );
            $this->assertIsString(
                $url,
                "{$source}: legal link [{$index}.url] must be a string.",
            );
            $this->assertNotSame(
                '#',
                trim($url),
                "{$source}: legal link [{$index}.url] must not be a placeholder.",
            );
            $this->assertNotFalse(
                filter_var(trim($url), FILTER_VALIDATE_URL),
                "{$source}: legal link [{$index}.url] must be an absolute URL.",
            );
        }
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }
}
