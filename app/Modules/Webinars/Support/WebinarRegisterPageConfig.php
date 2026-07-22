<?php

namespace App\Modules\Webinars\Support;

use Illuminate\Support\Arr;

class WebinarRegisterPageConfig
{
    /**
     * Content consumed by the reusable registration form/modal.
     *
     * @var array<int, string>
     */
    public const REGISTRATION_CONTENT_KEYS = [
        'form_card',
        'consents',
        'consent_header',
        'sections',
        'fields',
        'submit',
        'legal_links',
        'questions_section',
        'questions',
    ];

    /**
     * Landing-page sections that may be named in the shared series override
     * policy. A section is still protected unless the active policy sets its
     * value to true.
     *
     * @var array<int, string>
     */
    public const LANDING_CONTENT_KEYS = [
        'title',
        'meta_description',
        'header',
        'hero',
        'urgency_stats',
        'webinar_title',
        'primary_cta',
        'countdown',
        'event_details',
        'problem',
        'instructor',
        'secondary_cta',
        'trust',
        'final_close',
        'compliance',
        'sticky_desktop',
        'sticky_mobile',
        'blocks',
    ];

    /**
     * Styles used only by the reusable registration form/modal.
     *
     * @var array<int, string>
     */
    private const REGISTRATION_STYLE_KEYS = [
        'form_card',
        'consent_header',
        'legal_links',
    ];

    /**
     * Style groups that are useful to both the landing page and registration UI.
     *
     * @var array<int, string>
     */
    private const SHARED_STYLE_KEYS = [
        'tokens',
        'components',
    ];

    /**
     * Numeric landing-page collections whose meaning is positional only inside
     * one configuration layer. A later allowed layer replaces the complete
     * collection instead of merging items by numeric index.
     *
     * @var array<int, string>
     */
    private const ATOMIC_LANDING_LIST_PATHS = [
        'instructor.body',
        'instructor.credibility',
        'trust.reviews',
        'trust.stories',
    ];

    /**
     * @param array<string, mixed> $seriesMeta
     * @return array<string, mixed>
     */
    public function content(string $page, string $seriesSlug, array $seriesMeta = []): array
    {
        $global = config('webinars.content', []);
        $pageContent = config("webinars.{$page}.content", []);
        $seriesMetaContent = is_array($seriesMeta['public_page'] ?? null)
            ? $seriesMeta['public_page']
            : [];
        $seriesContent = config("webinars.{$page}.{$seriesSlug}.content", []);

        if ($page !== 'register') {
            return array_replace_recursive(
                is_array($global) ? $global : [],
                is_array($pageContent) ? $pageContent : [],
                $seriesMetaContent,
                is_array($seriesContent) ? $seriesContent : [],
            );
        }

        $pageContent = is_array($pageContent) ? $pageContent : [];
        $policy = $this->normalizeSeriesOverridePolicy(
            $pageContent['series_overrides'] ?? null,
        );

        return $this->mergeRegisterLayers([
            $this->normalizeRegisterContentLayer(is_array($global) ? $global : []),
            $this->normalizeRegisterContentLayer($pageContent),
            $this->filterSeriesContentLayer(
                $this->normalizeRegisterContentLayer($seriesMetaContent),
                $policy,
            ),
            $this->filterSeriesContentLayer(
                $this->normalizeRegisterContentLayer(
                    is_array($seriesContent) ? $seriesContent : [],
                ),
                $policy,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function style(string $page, string $seriesSlug): array
    {
        $global = config('webinars.style', []);
        $pageStyle = config("webinars.{$page}.style", []);
        $seriesStyle = config("webinars.{$page}.{$seriesSlug}.style", []);

        if ($page !== 'register') {
            return array_replace_recursive(
                is_array($global) ? $global : [],
                is_array($pageStyle) ? $pageStyle : [],
                is_array($seriesStyle) ? $seriesStyle : [],
            );
        }

        return $this->mergeRegisterLayers([
            $this->normalizeRegisterStyleLayer(is_array($global) ? $global : []),
            $this->normalizeRegisterStyleLayer(is_array($pageStyle) ? $pageStyle : []),
            $this->normalizeRegisterStyleLayer(is_array($seriesStyle) ? $seriesStyle : []),
        ]);
    }

    /**
     * Return the active, runtime-safe series override policy. Only known
     * sections explicitly set to true are retained.
     *
     * @return array{landing: array<string, bool>, registration: array<string, bool>}
     */
    public function seriesOverridePolicy(): array
    {
        return $this->normalizeSeriesOverridePolicy(
            config('webinars.register.content.series_overrides'),
        );
    }

    /**
     * Identify top-level series content sections that the active shared policy
     * does not permit. The returned paths use the normalized ownership buckets.
     *
     * @param array<string, mixed> $layer
     * @param array{landing?: array<string, bool>, registration?: array<string, bool>}|null $policy
     * @return array<int, string>
     */
    public function unauthorizedSeriesOverridePaths(
        array $layer,
        ?array $policy = null,
    ): array {
        $normalized = $this->normalizeRegisterContentLayer($layer);
        $policy = $policy ?? $this->seriesOverridePolicy();
        $paths = [];

        foreach (['landing', 'registration'] as $bucket) {
            $allowed = is_array($policy[$bucket] ?? null)
                ? $policy[$bucket]
                : [];

            foreach (array_keys($normalized[$bucket]) as $section) {
                if (($allowed[$section] ?? false) === true) {
                    continue;
                }

                $paths[] = "{$bucket}.{$section}";
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * @param array<int, array{landing: array<string, mixed>, registration: array<string, mixed>}> $layers
     * @return array{landing: array<string, mixed>, registration: array<string, mixed>}
     */
    private function mergeRegisterLayers(array $layers): array
    {
        $landing = [];
        $registration = [];

        foreach ($layers as $layer) {
            $landing = $this->mergeLandingLayer(
                current: $landing,
                incoming: $layer['landing'],
            );
            $registration = $this->mergeRegistrationLayer(
                current: $registration,
                incoming: $layer['registration'],
            );
        }

        return [
            'landing' => $landing,
            'registration' => $registration,
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeLandingLayer(
        array $current,
        array $incoming,
    ): array {
        $atomicValues = [];

        foreach (self::ATOMIC_LANDING_LIST_PATHS as $path) {
            if (! Arr::has($incoming, $path)) {
                continue;
            }

            $atomicValues[$path] = Arr::get($incoming, $path);
            Arr::forget($incoming, $path);
        }

        $merged = array_replace_recursive($current, $incoming);

        foreach ($atomicValues as $path => $value) {
            Arr::set($merged, $path, $value);
        }

        return $merged;
    }

    /**
     * Numeric question lists are atomic configuration. A later allowed layer
     * replaces the complete list rather than recursively merging questions and
     * options by numeric position.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeRegistrationLayer(
        array $current,
        array $incoming,
    ): array {
        $replacesQuestions = array_key_exists('questions', $incoming);
        $questions = $replacesQuestions ? $incoming['questions'] : null;

        if ($replacesQuestions) {
            unset($incoming['questions']);
        }

        $merged = array_replace_recursive($current, $incoming);

        if ($replacesQuestions) {
            $merged['questions'] = $questions;
        }

        return $merged;
    }

    /**
     * Accept both the explicit namespaced contract and legacy flat config.
     *
     * @param array<string, mixed> $layer
     * @return array{landing: array<string, mixed>, registration: array<string, mixed>}
     */
    private function normalizeRegisterContentLayer(array $layer): array
    {
        $explicitLanding = is_array($layer['landing'] ?? null)
            ? $layer['landing']
            : [];
        $explicitRegistration = is_array($layer['registration'] ?? null)
            ? $layer['registration']
            : [];

        unset(
            $layer['landing'],
            $layer['registration'],
            $layer['series_overrides'],
        );

        $legacyRegistration = $this->only($layer, self::REGISTRATION_CONTENT_KEYS);
        $legacyLanding = $this->except($layer, self::REGISTRATION_CONTENT_KEYS);

        return [
            'landing' => array_replace_recursive($legacyLanding, $explicitLanding),
            'registration' => array_replace_recursive($legacyRegistration, $explicitRegistration),
        ];
    }

    /**
     * Accept both the explicit namespaced contract and legacy flat config.
     * Shared token/component styles are copied into the registration contract.
     *
     * @param array<string, mixed> $layer
     * @return array{landing: array<string, mixed>, registration: array<string, mixed>}
     */
    private function normalizeRegisterStyleLayer(array $layer): array
    {
        $explicitLanding = is_array($layer['landing'] ?? null)
            ? $layer['landing']
            : [];
        $explicitRegistration = is_array($layer['registration'] ?? null)
            ? $layer['registration']
            : [];

        unset($layer['landing'], $layer['registration']);

        $shared = $this->only($layer, self::SHARED_STYLE_KEYS);
        $legacyRegistration = $this->only($layer, self::REGISTRATION_STYLE_KEYS);
        $legacyLanding = $this->except($layer, self::REGISTRATION_STYLE_KEYS);

        return [
            'landing' => array_replace_recursive($legacyLanding, $explicitLanding),
            'registration' => array_replace_recursive(
                $shared,
                $legacyRegistration,
                $explicitRegistration,
            ),
        ];
    }

    /**
     * @param array{landing: array<string, mixed>, registration: array<string, mixed>} $layer
     * @param array{landing: array<string, bool>, registration: array<string, bool>} $policy
     * @return array{landing: array<string, mixed>, registration: array<string, mixed>}
     */
    private function filterSeriesContentLayer(array $layer, array $policy): array
    {
        return [
            'landing' => $this->onlyEnabledSections(
                $layer['landing'],
                $policy['landing'],
            ),
            'registration' => $this->onlyEnabledSections(
                $layer['registration'],
                $policy['registration'],
            ),
        ];
    }

    /**
     * @return array{landing: array<string, bool>, registration: array<string, bool>}
     */
    private function normalizeSeriesOverridePolicy(mixed $policy): array
    {
        $policy = is_array($policy) ? $policy : [];

        return [
            'landing' => $this->normalizePolicyBucket(
                $policy['landing'] ?? null,
                self::LANDING_CONTENT_KEYS,
            ),
            'registration' => $this->normalizePolicyBucket(
                $policy['registration'] ?? null,
                self::REGISTRATION_CONTENT_KEYS,
            ),
        ];
    }

    /**
     * @param array<int, string> $knownSections
     * @return array<string, bool>
     */
    private function normalizePolicyBucket(
        mixed $bucket,
        array $knownSections,
    ): array {
        if (! is_array($bucket)) {
            return [];
        }

        $normalized = [];

        foreach ($knownSections as $section) {
            if (($bucket[$section] ?? false) === true) {
                $normalized[$section] = true;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $sections
     * @param array<string, bool> $policy
     * @return array<string, mixed>
     */
    private function onlyEnabledSections(array $sections, array $policy): array
    {
        return array_filter(
            $sections,
            fn (mixed $section): bool => is_string($section)
                && ($policy[$section] ?? false) === true,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function only(array $values, array $keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function except(array $values, array $keys): array
    {
        return array_diff_key($values, array_flip($keys));
    }
}