<?php

namespace Tests\Feature\ConfigGeneration;

use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarRegistrationConsentContentConfigTest extends TestCase
{
    public function test_core_registration_defaults_enable_all_four_consent_fields(): void
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

        $this->assertSame([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => true, 'sms' => true],
        ], data_get($content, 'registration.consents'));

        $this->assertRenderableRegistrationContract(
            $content['registration'],
            'core registration defaults',
        );
    }

    public function test_every_client_registration_config_resolves_its_own_consent_contract(): void
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

    public function test_disabled_consent_fields_do_not_require_unused_sections_or_copy(): void
    {
        Config::set('webinars.content', []);
        Config::set('webinars.register.content', [
            'registration' => [
                'consents' => [
                    'transactional' => ['email' => true, 'sms' => false],
                    'marketing' => ['email' => false, 'sms' => false],
                ],
                'consent_header' => [
                    'enabled' => false,
                ],
                'sections' => [
                    'notifications' => [
                        'title' => 'Email registration',
                    ],
                ],
                'fields' => [
                    'first_name' => ['label' => 'First name'],
                    'last_name' => ['label' => 'Last name'],
                    'email' => ['label' => 'Email'],
                    'phone' => ['label' => 'Phone'],
                    'consent_messages' => [
                        'email' => ['label' => 'Email me webinar details.'],
                    ],
                ],
                'legal_links' => [
                    'enabled' => false,
                    'links' => [],
                ],
            ],
        ]);
        Config::set('webinars.register.email-only.content', []);

        $resolved = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'email-only',
        );

        $this->assertRenderableRegistrationContract(
            $resolved['registration'],
            'transactional-email-only contract',
        );
    }

    public function test_explicit_series_policy_can_allow_registration_copy_overrides(): void
    {
        Config::set('webinars.content', []);
        Config::set('webinars.register.content', [
            'series_overrides' => [
                'landing' => [],
                'registration' => [
                    'sections' => true,
                ],
            ],
            'registration' => [
                'consents' => [
                    'transactional' => ['email' => true, 'sms' => false],
                    'marketing' => ['email' => false, 'sms' => false],
                ],
                'sections' => [
                    'notifications' => [
                        'title' => 'Base delivery title',
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
            'Base webinar email label.',
            data_get($content, 'registration.fields.consent_messages.email.label'),
        );
        $this->assertSame(
            'Base phone helper.',
            data_get($content, 'registration.fields.phone.helper'),
        );
        $this->assertFalse(data_get($content, 'registration.consents.marketing.email'));
        $this->assertFalse(data_get($content, 'registration.consents.marketing.sms'));
    }

    /**
     * @param array<string, mixed> $registration
     */
    private function assertRenderableRegistrationContract(
        array $registration,
        string $source,
    ): void {
        foreach ([
            'fields.first_name.label',
            'fields.last_name.label',
            'fields.email.label',
            'fields.phone.label',
        ] as $path) {
            $this->assertNonBlankString(data_get($registration, $path), $source, $path);
        }

        $consents = data_get($registration, 'consents');

        $this->assertIsArray(
            $consents,
            "{$source}: [consents] must resolve to an array.",
        );

        foreach (['transactional', 'marketing'] as $purpose) {
            foreach (['email', 'sms'] as $channel) {
                $path = "{$purpose}.{$channel}";
                $value = data_get($consents, $path);

                $this->assertIsBool(
                    $value,
                    "{$source}: [consents.{$path}] must resolve to a boolean.",
                );
            }
        }

        $this->assertTrue(
            data_get($consents, 'transactional.email')
                || data_get($consents, 'transactional.sms'),
            "{$source}: at least one transactional consent field must be enabled.",
        );

        if ($this->purposeEnabled($consents, 'transactional')) {
            $this->assertNonBlankString(
                data_get($registration, 'sections.notifications.title'),
                $source,
                'sections.notifications.title',
            );
        }

        if ($this->purposeEnabled($consents, 'marketing')) {
            $this->assertNonBlankString(
                data_get($registration, 'sections.marketing.title'),
                $source,
                'sections.marketing.title',
            );
        }

        foreach ([
            'transactional.email' => 'fields.consent_messages.email.label',
            'transactional.sms' => 'fields.consent_messages.sms.label',
        ] as $consentPath => $requiredPath) {
            if (data_get($consents, $consentPath) !== true) {
                continue;
            }

            $this->assertNonBlankString(
                data_get($registration, $requiredPath),
                $source,
                $requiredPath,
            );
        }

        if (data_get($consents, 'transactional.sms') === true) {
            $this->assertDisclosureContract(
                registration: $registration,
                fieldPath: 'fields.consent_messages.sms',
                source: $source,
            );
        }

        $combinedMarketing = data_get($consents, 'marketing.combined') === true
            && data_get($consents, 'marketing.email') === true
            && data_get($consents, 'marketing.sms') === true;

        if ($combinedMarketing) {
            $this->assertNonBlankString(
                data_get(
                    $registration,
                    'fields.marketing_consent_messages.combined.label',
                ),
                $source,
                'fields.marketing_consent_messages.combined.label',
            );
            $this->assertDisclosureContract(
                registration: $registration,
                fieldPath: 'fields.marketing_consent_messages.combined',
                source: $source,
            );
        } else {
            if (data_get($consents, 'marketing.email') === true) {
                $this->assertNonBlankString(
                    data_get(
                        $registration,
                        'fields.marketing_consent_messages.email.label',
                    ),
                    $source,
                    'fields.marketing_consent_messages.email.label',
                );
            }

            if (data_get($consents, 'marketing.sms') === true) {
                $this->assertNonBlankString(
                    data_get(
                        $registration,
                        'fields.marketing_consent_messages.sms.label',
                    ),
                    $source,
                    'fields.marketing_consent_messages.sms.label',
                );
                $this->assertDisclosureContract(
                    registration: $registration,
                    fieldPath: 'fields.marketing_consent_messages.sms',
                    source: $source,
                );
            }
        }

        $consentHeader = data_get($registration, 'consent_header', []);

        $this->assertIsArray(
            $consentHeader,
            "{$source}: [consent_header] must resolve to an array.",
        );

        if (($consentHeader['enabled'] ?? true) === true) {
            $this->assertNonBlankString(
                $consentHeader['body'] ?? null,
                $source,
                'consent_header.body',
            );
        }

        $this->assertSafeLegalLinks(
            data_get($registration, 'legal_links', []),
            $source,
        );
    }

    /**
     * @param array<string, mixed> $consents
     */
    private function purposeEnabled(array $consents, string $purpose): bool
    {
        return data_get($consents, "{$purpose}.email") === true
            || data_get($consents, "{$purpose}.sms") === true;
    }

    /**
     * @param array<string, mixed> $registration
     */
    private function assertDisclosureContract(
        array $registration,
        string $fieldPath,
        string $source,
    ): void {
        $referencesPath = "{$fieldPath}.disclosure_refs";
        $references = data_get($registration, $referencesPath);

        $this->assertIsArray(
            $references,
            "{$source}: [{$fieldPath}] must configure disclosure_refs.",
        );

        if (! is_array($references)) {
            return;
        }

        $this->assertTrue(
            array_is_list($references),
            "{$source}: [{$referencesPath}] must be a list.",
        );
        $this->assertNotEmpty(
            $references,
            "{$source}: [{$referencesPath}] must not be empty.",
        );
        $this->assertSame(
            count($references),
            count(array_unique($references, SORT_REGULAR)),
            "{$source}: [{$referencesPath}] must not contain duplicate keys.",
        );

        foreach ($references as $index => $reference) {
            $referencePath = "{$referencesPath}.{$index}";

            $this->assertNonBlankString(
                $reference,
                $source,
                $referencePath,
            );

            if (! is_string($reference) || trim($reference) === '') {
                continue;
            }

            $definitionPath = 'disclosures.items.'
                .trim($reference)
                .'.text';

            $this->assertNonBlankString(
                data_get($registration, $definitionPath),
                $source,
                $definitionPath,
            );
        }
    }

    private function assertNonBlankString(mixed $value, string $source, string $path): void
    {
        $this->assertIsString(
            $value,
            "{$source}: [{$path}] must resolve to a string.",
        );

        if (! is_string($value)) {
            return;
        }

        $this->assertNotSame(
            '',
            trim($value),
            "{$source}: [{$path}] must not be blank.",
        );
    }

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

            $this->assertNonBlankString(
                $label,
                $source,
                "legal_links.links.{$index}.label",
            );
            $this->assertIsString(
                $url,
                "{$source}: legal link [{$index}.url] must be a string.",
            );

            if (! is_string($url)) {
                continue;
            }

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