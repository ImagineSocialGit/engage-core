<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use InvalidArgumentException;

class WebinarRegisterPageDefinitionValidator
{
    private const SUPPORTED_TRUST_VARIANTS = [
        'reviews',
        'stories',
    ];

    public function __construct(
        private readonly WebinarRegisterPageConfig $pageConfig,
        private readonly WebinarRegistrationQuestionResolver $questionResolver,
    ) {}

    /**
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    public function validateOverridePolicy(mixed $policy, string $path): array
    {
        if (! is_array($policy)) {
            return [$this->violation(
                code: 'webinars.register_page.override_policy_invalid',
                message: 'Webinar registration series_overrides must be an array.',
                path: $path,
                context: ['received_type' => get_debug_type($policy)],
            )];
        }

        $violations = [];
        $knownBuckets = [
            'landing' => WebinarRegisterPageConfig::LANDING_CONTENT_KEYS,
            'registration' => WebinarRegisterPageConfig::REGISTRATION_CONTENT_KEYS,
        ];

        foreach (array_keys($policy) as $bucket) {
            if (is_string($bucket) && array_key_exists($bucket, $knownBuckets)) {
                continue;
            }

            $violations[] = $this->violation(
                code: 'webinars.register_page.override_policy_invalid',
                message: 'Webinar registration series_overrides contains an unknown ownership bucket.',
                path: $path.'.'.(is_scalar($bucket) ? (string) $bucket : 'unknown'),
            );
        }

        foreach ($knownBuckets as $bucket => $knownSections) {
            $bucketPath = "{$path}.{$bucket}";
            $definitions = $policy[$bucket] ?? null;

            if (! is_array($definitions)) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.override_policy_invalid',
                    message: "Webinar registration series_overrides [{$bucket}] must be an array of section booleans.",
                    path: $bucketPath,
                    context: ['received_type' => get_debug_type($definitions)],
                );

                continue;
            }

            foreach ($definitions as $section => $enabled) {
                $sectionPath = $bucketPath.'.'.(
                    is_scalar($section) ? (string) $section : 'unknown'
                );

                if (! is_string($section)
                    || trim($section) === ''
                    || ! in_array($section, $knownSections, true)
                ) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.override_policy_invalid',
                        message: "Webinar registration series_overrides [{$bucket}] contains an unknown section.",
                        path: $sectionPath,
                    );

                    continue;
                }

                if (! is_bool($enabled)) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.override_policy_invalid',
                        message: 'Webinar registration series override permissions must be boolean values.',
                        path: $sectionPath,
                        context: ['received_type' => get_debug_type($enabled)],
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Validate one raw series metadata or series-file content layer before it
     * is filtered by the shared ownership policy.
     *
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    public function validateSeriesDefinition(mixed $definition, string $path): array
    {
        if (! is_array($definition)) {
            return [$this->violation(
                code: 'webinars.register_page.definition_invalid',
                message: 'Webinar registration series content must be an array.',
                path: $path,
                context: ['received_type' => get_debug_type($definition)],
            )];
        }

        $violations = [];

        foreach (['landing', 'registration'] as $bucket) {
            if (array_key_exists($bucket, $definition)
                && ! is_array($definition[$bucket])
            ) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.definition_invalid',
                    message: "Webinar registration series [{$bucket}] content must be an array.",
                    path: "{$path}.{$bucket}",
                    context: [
                        'received_type' => get_debug_type($definition[$bucket]),
                    ],
                );
            }
        }

        foreach ($this->pageConfig->unauthorizedSeriesOverridePaths($definition) as $overridePath) {
            $violations[] = $this->violation(
                code: 'webinars.register_page.series_override_forbidden',
                message: "Webinar registration series content may not override [{$overridePath}] under the active shared policy.",
                path: "{$path}.{$overridePath}",
                context: ['override_path' => $overridePath],
            );
        }

        return $violations;
    }

    /**
     * Validate a fully resolved registration-page definition after permitted
     * series overrides have been applied.
     *
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    public function validateResolvedDefinition(mixed $definition, string $path): array
    {
        if (! is_array($definition)) {
            return [$this->violation(
                code: 'webinars.register_page.definition_invalid',
                message: 'Resolved Webinar registration page content must be an array.',
                path: $path,
                context: ['received_type' => get_debug_type($definition)],
            )];
        }

        $landing = $definition['landing'] ?? null;
        $registration = $definition['registration'] ?? null;
        $violations = [];

        if (! is_array($landing)) {
            $violations[] = $this->violation(
                code: 'webinars.register_page.definition_invalid',
                message: 'Resolved Webinar registration landing content must be an array.',
                path: "{$path}.landing",
                context: ['received_type' => get_debug_type($landing)],
            );
        }

        if (! is_array($registration)) {
            $violations[] = $this->violation(
                code: 'webinars.register_page.definition_invalid',
                message: 'Resolved Webinar registration form content must be an array.',
                path: "{$path}.registration",
                context: ['received_type' => get_debug_type($registration)],
            );
        }

        if (! is_array($landing) || ! is_array($registration)) {
            return $violations;
        }

        $violations = array_merge(
            $violations,
            $this->validateDisclosures(
                $registration,
                "{$path}.registration",
            ),
            $this->validateQuestions(
                $registration['questions'] ?? null,
                "{$path}.registration.questions",
            ),
            $this->validateInstructor(
                $landing['instructor'] ?? null,
                "{$path}.landing.instructor",
            ),
            $this->validateTrust(
                $landing['trust'] ?? null,
                "{$path}.landing.trust",
            ),
        );

        return $violations;
    }

    /**
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    /**
     * @param array<string, mixed> $registration
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    private function validateDisclosures(
        array $registration,
        string $path,
    ): array {
        $definitions = $registration['disclosures'] ?? null;
        $violations = [];
        $knownKeys = [];

        if ($definitions !== null) {
            if (! is_array($definitions)) {
                return [$this->violation(
                    code: 'webinars.register_page.disclosure_definition_invalid',
                    message: 'Webinar registration disclosures must be an array.',
                    path: "{$path}.disclosures",
                    context: [
                        'received_type' => get_debug_type($definitions),
                    ],
                )];
            }

            $items = $definitions['items'] ?? [];

            if (! is_array($items)) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.disclosure_definition_invalid',
                    message: 'Webinar registration disclosure items must be an associative array.',
                    path: "{$path}.disclosures.items",
                    context: [
                        'received_type' => get_debug_type($items),
                    ],
                );

                $items = [];
            }

            foreach ($items as $key => $item) {
                $displayKey = is_scalar($key) ? (string) $key : 'unknown';
                $itemPath = "{$path}.disclosures.items.{$displayKey}";
                $validKey = is_string($key)
                    && preg_match('/^[a-z][a-z0-9_]{0,99}$/', $key) === 1;

                if (! $validKey) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.disclosure_definition_invalid',
                        message: 'Webinar registration disclosure keys must be lower snake_case identifiers beginning with a letter.',
                        path: $itemPath,
                    );
                } else {
                    $knownKeys[$key] = true;
                }

                if (! is_array($item)) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.disclosure_definition_invalid',
                        message: 'Each Webinar registration disclosure item must be an array.',
                        path: $itemPath,
                        context: [
                            'received_type' => get_debug_type($item),
                        ],
                    );

                    continue;
                }

                if (! $this->filledString($item['text'] ?? null)) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.disclosure_definition_invalid',
                        message: 'Each Webinar registration disclosure item requires non-empty text.',
                        path: "{$itemPath}.text",
                    );
                }

                if (array_key_exists('marker', $item)
                    && $item['marker'] !== null
                ) {
                    $marker = $item['marker'];

                    if (! is_scalar($marker)
                        || trim((string) $marker) === ''
                        || strlen(trim((string) $marker)) > 10
                    ) {
                        $violations[] = $this->violation(
                            code: 'webinars.register_page.disclosure_definition_invalid',
                            message: 'Webinar registration disclosure markers must be non-empty scalar values no longer than 10 bytes.',
                            path: "{$itemPath}.marker",
                            context: [
                                'received_type' => get_debug_type($marker),
                            ],
                        );
                    }
                }

                if (array_key_exists('label', $item)
                    && $item['label'] !== null
                    && ! $this->filledString($item['label'])
                ) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.disclosure_definition_invalid',
                        message: 'Webinar registration disclosure labels must be non-empty strings when configured.',
                        path: "{$itemPath}.label",
                        context: [
                            'received_type' => get_debug_type($item['label']),
                        ],
                    );
                }
            }
        }

        foreach ([
            'fields.consent_messages.email',
            'fields.consent_messages.sms',
            'fields.marketing_consent_messages.combined',
            'fields.marketing_consent_messages.email',
            'fields.marketing_consent_messages.sms',
        ] as $fieldPath) {
            $field = data_get($registration, $fieldPath);

            if (! is_array($field)) {
                continue;
            }

            if (array_key_exists('disclosure', $field)) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.inline_disclosure_unsupported',
                    message: 'Webinar registration consent disclosures must use disclosure_refs and shared disclosure definitions.',
                    path: "{$path}.{$fieldPath}.disclosure",
                );
            }

            if (! array_key_exists('disclosure_refs', $field)) {
                continue;
            }

            $references = $field['disclosure_refs'];
            $referencesPath = "{$path}.{$fieldPath}.disclosure_refs";

            if (! is_array($references) || ! array_is_list($references)) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.disclosure_reference_invalid',
                    message: 'Webinar registration disclosure_refs must be a list of disclosure keys.',
                    path: $referencesPath,
                    context: [
                        'received_type' => get_debug_type($references),
                    ],
                );

                continue;
            }

            $seenReferences = [];

            foreach ($references as $index => $reference) {
                $referencePath = "{$referencesPath}.{$index}";

                if (! is_string($reference)
                    || preg_match('/^[a-z][a-z0-9_]{0,99}$/', $reference) !== 1
                ) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.disclosure_reference_invalid',
                        message: 'Webinar registration disclosure references must be lower snake_case keys.',
                        path: $referencePath,
                        context: [
                            'received_type' => get_debug_type($reference),
                        ],
                    );

                    continue;
                }

                if (isset($seenReferences[$reference])) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.disclosure_reference_invalid',
                        message: "Webinar registration disclosure reference [{$reference}] is duplicated.",
                        path: $referencePath,
                        context: [
                            'disclosure_key' => $reference,
                        ],
                    );

                    continue;
                }

                $seenReferences[$reference] = true;

                if (! isset($knownKeys[$reference])) {
                    $violations[] = $this->violation(
                        code: 'webinars.register_page.disclosure_reference_invalid',
                        message: "Webinar registration disclosure reference [{$reference}] does not resolve to a configured item.",
                        path: $referencePath,
                        context: [
                            'disclosure_key' => $reference,
                        ],
                    );
                }
            }
        }

        return $violations;
    }

    private function validateQuestions(mixed $questions, string $path): array
    {
        try {
            $this->questionResolver->resolve($questions);
        } catch (InvalidArgumentException $exception) {
            return [$this->violation(
                code: 'webinars.register_page.question_definition_invalid',
                message: $exception->getMessage(),
                path: $path,
                context: ['exception' => $exception::class],
            )];
        }

        return [];
    }

    /**
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    private function validateInstructor(mixed $instructor, string $path): array
    {
        if ($instructor === null) {
            return [];
        }

        if (! is_array($instructor)) {
            return [$this->violation(
                code: 'webinars.register_page.definition_invalid',
                message: 'Webinar registration instructor content must be an array.',
                path: $path,
                context: ['received_type' => get_debug_type($instructor)],
            )];
        }

        $violations = [];

        foreach (['body', 'credibility'] as $collection) {
            if (! array_key_exists($collection, $instructor)) {
                continue;
            }

            $value = $instructor[$collection];

            if (! is_array($value) || ! array_is_list($value)) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.collection_invalid',
                    message: "Webinar registration instructor [{$collection}] must be a list.",
                    path: "{$path}.{$collection}",
                    context: ['received_type' => get_debug_type($value)],
                );
            }
        }

        return $violations;
    }

    /**
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    private function validateTrust(mixed $trust, string $path): array
    {
        if ($trust === null) {
            return [];
        }

        if (! is_array($trust)) {
            return [$this->violation(
                code: 'webinars.register_page.definition_invalid',
                message: 'Webinar registration trust content must be an array.',
                path: $path,
                context: ['received_type' => get_debug_type($trust)],
            )];
        }

        $variant = is_string($trust['variant'] ?? null)
            ? trim($trust['variant'])
            : '';
        $violations = [];

        if (! in_array($variant, self::SUPPORTED_TRUST_VARIANTS, true)) {
            $violations[] = $this->violation(
                code: 'webinars.register_page.trust_variant_invalid',
                message: 'Webinar registration trust.variant must be reviews or stories.',
                path: "{$path}.variant",
                context: ['configured_value' => $trust['variant'] ?? null],
            );

            return $violations;
        }

        foreach (['reviews', 'stories'] as $collection) {
            if (! array_key_exists($collection, $trust)) {
                continue;
            }

            $value = $trust[$collection];

            if (! is_array($value) || ! array_is_list($value)) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.collection_invalid',
                    message: "Webinar registration trust [{$collection}] must be a list.",
                    path: "{$path}.{$collection}",
                    context: ['received_type' => get_debug_type($value)],
                );
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        $reviews = is_array($trust['reviews'] ?? null)
            ? $trust['reviews']
            : [];
        $stories = is_array($trust['stories'] ?? null)
            ? $trust['stories']
            : [];

        $violations = array_merge(
            $violations,
            $this->validateReviews($reviews, "{$path}.reviews"),
            $this->validateStories($stories, "{$path}.stories"),
        );

        if (($trust['enabled'] ?? false) !== true) {
            return $violations;
        }

        $selected = $variant === 'reviews' ? $reviews : $stories;
        $enabledCount = collect($selected)
            ->filter(fn (mixed $item): bool => is_array($item)
                && ($item['is_enabled'] ?? true) === true)
            ->count();

        if ($enabledCount === 0) {
            $violations[] = $this->violation(
                code: 'webinars.register_page.trust_content_missing',
                message: "Enabled Webinar registration trust variant [{$variant}] requires at least one enabled item.",
                path: "{$path}.{$variant}",
            );
        }

        return $violations;
    }

    /**
     * @param array<int, mixed> $reviews
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    private function validateReviews(array $reviews, string $path): array
    {
        $violations = [];

        foreach ($reviews as $index => $review) {
            $itemPath = "{$path}.{$index}";

            if (! is_array($review)) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.definition_invalid',
                    message: 'Each Webinar registration review must be an array.',
                    path: $itemPath,
                    context: ['received_type' => get_debug_type($review)],
                );

                continue;
            }

            if (($review['is_enabled'] ?? true) !== true) {
                continue;
            }

            foreach (['name', 'text'] as $required) {
                if ($this->filledString($review[$required] ?? null)) {
                    continue;
                }

                $violations[] = $this->violation(
                    code: 'webinars.register_page.trust_content_missing',
                    message: "Enabled Webinar registration review requires [{$required}].",
                    path: "{$itemPath}.{$required}",
                );
            }

            $violations = array_merge(
                $violations,
                $this->validateRating($review, $itemPath),
            );
        }

        return $violations;
    }

    /**
     * @param array<int, mixed> $stories
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    private function validateStories(array $stories, string $path): array
    {
        $violations = [];
        $seenKeys = [];

        foreach ($stories as $index => $story) {
            $itemPath = "{$path}.{$index}";

            if (! is_array($story)) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.definition_invalid',
                    message: 'Each Webinar registration story must be an array.',
                    path: $itemPath,
                    context: ['received_type' => get_debug_type($story)],
                );

                continue;
            }

            $key = is_string($story['key'] ?? null)
                ? trim($story['key'])
                : '';

            if ($key === '' || preg_match('/^[a-z][a-z0-9_]{0,99}$/', $key) !== 1) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.story_key_invalid',
                    message: 'Webinar registration story keys must use lowercase snake_case and begin with a letter.',
                    path: "{$itemPath}.key",
                );
            } elseif (isset($seenKeys[$key])) {
                $violations[] = $this->violation(
                    code: 'webinars.register_page.story_key_duplicate',
                    message: "Webinar registration story key [{$key}] is duplicated.",
                    path: "{$itemPath}.key",
                    context: ['story_key' => $key],
                );
            } else {
                $seenKeys[$key] = true;
            }

            if (($story['is_enabled'] ?? true) !== true) {
                continue;
            }

            foreach (['title', 'context', 'outcome', 'quote'] as $required) {
                if ($this->filledString($story[$required] ?? null)) {
                    continue;
                }

                $violations[] = $this->violation(
                    code: 'webinars.register_page.trust_content_missing',
                    message: "Enabled Webinar registration story requires [{$required}].",
                    path: "{$itemPath}.{$required}",
                );
            }

            $violations = array_merge(
                $violations,
                $this->validateRating($story, $itemPath),
            );
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int, array{code: string, message: string, path: string, context: array<string, mixed>}>
     */
    private function validateRating(array $item, string $path): array
    {
        if (array_key_exists('rating', $item)) {
            $rating = $item['rating'];

            if (is_int($rating) && $rating >= 1 && $rating <= 5) {
                return [];
            }

            return [$this->violation(
                code: 'webinars.register_page.rating_invalid',
                message: 'Webinar registration trust ratings must be integers between 1 and 5.',
                path: "{$path}.rating",
                context: ['configured_value' => $rating],
            )];
        }

        $stars = $item['stars'] ?? null;

        if (is_string($stars)) {
            $normalized = preg_replace('/\s+/u', '', trim($stars));
            $count = substr_count((string) $normalized, '★');

            if ($count >= 1
                && $count <= 5
                && $normalized === str_repeat('★', $count)
            ) {
                return [];
            }
        }

        return [$this->violation(
            code: 'webinars.register_page.rating_invalid',
            message: 'Enabled Webinar registration trust items require a numeric rating or a one-to-five-star string.',
            path: $path,
        )];
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param array<string, mixed> $context
     * @return array{code: string, message: string, path: string, context: array<string, mixed>}
     */
    private function violation(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'path' => $path,
            'context' => $context,
        ];
    }
}