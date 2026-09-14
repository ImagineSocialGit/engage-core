<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\PublishedForm;
use DomainException;
use Illuminate\Support\Str;

final class HostedFormLayoutResolver
{
    public const BLOCK_TYPES = [
        'content',
        'field',
    ];

    public const CONTENT_VARIANTS = [
        'body',
        'section',
        'subsection',
        'note',
        'callout',
        'example',
    ];

    public const CONDITION_MATCH_MODES = [
        'all',
        'any',
    ];

    public const CONDITION_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'filled',
    ];

    private const BLOCK_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    /**
     * @return array{
     *     enabled: bool,
     *     slug: string,
     *     blocks: array<int, array<string, mixed>>,
     *     conditions: array<int, array<string, mixed>>,
     *     submit_label: string,
     *     success: array{heading: string, message: string},
     *     branding: array{logo_url: string|null, primary_color: string, accent_color: string}
     * }
     */
    public function resolve(PublishedForm $form): array
    {
        $hostedSettings = $this->hostedSettings($form);
        $enabled = $hostedSettings['enabled'] ?? false;

        if (! is_bool($enabled)) {
            throw $this->invalid($form, 'settings.public.hosted.enabled must be a boolean.');
        }

        $blocks = $this->blocks($form);
        $blockKeys = array_fill_keys(array_map(
            static fn (array $block): string => (string) $block['key'],
            $blocks,
        ), true);
        $conditions = $this->conditions($form, $blockKeys);

        return [
            'enabled' => $enabled,
            'slug' => Str::of($form->key)->replace('_', '-')->toString(),
            'blocks' => $blocks,
            'conditions' => $conditions,
            'submit_label' => $this->stringSetting(
                $form,
                $hostedSettings['submit_label'] ?? 'Submit',
                'settings.public.hosted.submit_label',
                120,
            ),
            'success' => $this->success($form, $hostedSettings['success'] ?? []),
            'branding' => $this->branding($form, $hostedSettings['branding'] ?? []),
        ];
    }

    public function validate(PublishedForm $form): void
    {
        $this->resolve($form);
    }

    /**
     * @return array<string, mixed>
     */
    private function hostedSettings(PublishedForm $form): array
    {
        $public = $form->settings['public'] ?? [];

        if ($public === null) {
            return [];
        }

        if (! is_array($public)) {
            throw $this->invalid($form, 'settings.public must be an array.');
        }

        $hosted = $public['hosted'] ?? [];

        if ($hosted === null) {
            return [];
        }

        if (! is_array($hosted)) {
            throw $this->invalid($form, 'settings.public.hosted must be an array.');
        }

        return $hosted;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blocks(PublishedForm $form): array
    {
        $authoredBlocks = $form->layout['blocks'] ?? null;

        if ($authoredBlocks === null || $authoredBlocks === []) {
            return $this->defaultBlocks($form);
        }

        if (! is_array($authoredBlocks) || ! array_is_list($authoredBlocks)) {
            throw $this->invalid(
                $form,
                'layout.blocks must be a non-empty list when authored blocks are configured.',
            );
        }

        $knownFields = array_fill_keys($form->fieldKeys(), true);
        $requiredFieldBlocks = array_fill_keys(array_values(array_map(
            static fn (array $field): string => (string) $field['key'],
            array_values(array_filter(
                $form->fields,
                static fn (array $field): bool => ($field['type'] ?? null) !== 'hidden',
            )),
        )), false);
        $seenBlockKeys = [];
        $resolved = [];

        foreach ($authoredBlocks as $index => $block) {
            if (! is_array($block)) {
                throw $this->invalid($form, "layout.blocks.{$index} must be an array.");
            }

            $type = strtolower(trim((string) ($block['type'] ?? '')));

            if (! in_array($type, self::BLOCK_TYPES, true)) {
                throw $this->invalid(
                    $form,
                    "layout.blocks.{$index}.type [{$type}] is unsupported.",
                );
            }

            if ($type === 'field') {
                $fieldKey = trim((string) ($block['field'] ?? ''));

                if ($fieldKey === '' || ! isset($knownFields[$fieldKey])) {
                    throw $this->invalid(
                        $form,
                        "layout.blocks.{$index}.field must reference a known field.",
                    );
                }

                if (($form->field($fieldKey)['type'] ?? null) === 'hidden') {
                    throw $this->invalid(
                        $form,
                        "layout.blocks.{$index}.field cannot render hidden field [{$fieldKey}] as a visible block.",
                    );
                }

                if (($requiredFieldBlocks[$fieldKey] ?? null) === true) {
                    throw $this->invalid(
                        $form,
                        "layout.blocks contains duplicate field block [{$fieldKey}].",
                    );
                }

                $key = $fieldKey;
                $requiredFieldBlocks[$fieldKey] = true;
                $resolved[] = [
                    'type' => 'field',
                    'key' => $key,
                    'field' => $fieldKey,
                ];
            } else {
                $key = $this->blockKey(
                    $form,
                    $block['key'] ?? null,
                    "layout.blocks.{$index}.key",
                );
                $variant = strtolower(trim((string) ($block['variant'] ?? 'body')));

                if (! in_array($variant, self::CONTENT_VARIANTS, true)) {
                    throw $this->invalid(
                        $form,
                        "layout.blocks.{$index}.variant [{$variant}] is unsupported.",
                    );
                }

                $text = $this->stringSetting(
                    $form,
                    $block['text'] ?? null,
                    "layout.blocks.{$index}.text",
                    20000,
                );

                $resolved[] = [
                    'type' => 'content',
                    'key' => $key,
                    'variant' => $variant,
                    'text' => $text,
                ];
            }

            if (isset($seenBlockKeys[$key])) {
                throw $this->invalid(
                    $form,
                    "layout.blocks contains duplicate block key [{$key}].",
                );
            }

            if ($type === 'content' && isset($knownFields[$key])) {
                throw $this->invalid(
                    $form,
                    "layout.blocks content block key [{$key}] collides with a form field key.",
                );
            }

            $seenBlockKeys[$key] = true;
        }

        foreach ($requiredFieldBlocks as $fieldKey => $included) {
            if ($included !== true) {
                throw $this->invalid(
                    $form,
                    "layout.blocks must render non-hidden field [{$fieldKey}] exactly once.",
                );
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, bool> $blockKeys
     * @return array<int, array<string, mixed>>
     */
    private function conditions(PublishedForm $form, array $blockKeys): array
    {
        $authored = $form->layout['conditions'] ?? [];

        if ($authored === null || $authored === []) {
            return [];
        }

        if (! is_array($authored) || ! array_is_list($authored)) {
            throw $this->invalid($form, 'layout.conditions must be a list.');
        }

        $knownFields = array_fill_keys($form->fieldKeys(), true);
        $conditionedTargets = [];
        $resolved = [];

        foreach ($authored as $index => $condition) {
            if (! is_array($condition)) {
                throw $this->invalid(
                    $form,
                    "layout.conditions.{$index} must be an array.",
                );
            }

            $targets = $condition['targets'] ?? null;

            if (! is_array($targets) || ! array_is_list($targets) || $targets === []) {
                throw $this->invalid(
                    $form,
                    "layout.conditions.{$index}.targets must be a non-empty list.",
                );
            }

            $normalizedTargets = [];

            foreach ($targets as $targetIndex => $target) {
                if (! is_string($target)) {
                    throw $this->invalid(
                        $form,
                        "layout.conditions.{$index}.targets.{$targetIndex} must be a string.",
                    );
                }

                $target = trim($target);

                if ($target === '' || ! isset($blockKeys[$target])) {
                    throw $this->invalid(
                        $form,
                        "layout.conditions.{$index}.targets references unknown block [{$target}].",
                    );
                }

                if (isset($conditionedTargets[$target])) {
                    throw $this->invalid(
                        $form,
                        "Form block [{$target}] may be targeted by only one visibility condition.",
                    );
                }

                $conditionedTargets[$target] = true;
                $normalizedTargets[] = $target;
            }

            $match = strtolower(trim((string) ($condition['match'] ?? 'all')));

            if (! in_array($match, self::CONDITION_MATCH_MODES, true)) {
                throw $this->invalid(
                    $form,
                    "layout.conditions.{$index}.match [{$match}] is unsupported.",
                );
            }

            $when = $condition['when'] ?? null;

            if (! is_array($when) || ! array_is_list($when) || $when === []) {
                throw $this->invalid(
                    $form,
                    "layout.conditions.{$index}.when must be a non-empty list.",
                );
            }

            $normalizedWhen = [];

            foreach ($when as $predicateIndex => $predicate) {
                if (! is_array($predicate)) {
                    throw $this->invalid(
                        $form,
                        "layout.conditions.{$index}.when.{$predicateIndex} must be an array.",
                    );
                }

                $field = trim((string) ($predicate['field'] ?? ''));

                if ($field === '' || ! isset($knownFields[$field])) {
                    throw $this->invalid(
                        $form,
                        "layout.conditions.{$index}.when.{$predicateIndex}.field must reference a known field.",
                    );
                }

                $operator = strtolower(trim((string) ($predicate['operator'] ?? 'equals')));

                if (! in_array($operator, self::CONDITION_OPERATORS, true)) {
                    throw $this->invalid(
                        $form,
                        "layout.conditions.{$index}.when.{$predicateIndex}.operator [{$operator}] is unsupported.",
                    );
                }

                if ($operator === 'contains'
                    && ($form->field($field)['type'] ?? null) !== 'checkboxes'
                ) {
                    throw $this->invalid(
                        $form,
                        "Form contains conditions require a checkboxes source field; [{$field}] is not checkboxes.",
                    );
                }

                $normalizedPredicate = [
                    'field' => $field,
                    'operator' => $operator,
                ];

                if ($operator !== 'filled') {
                    if (! array_key_exists('value', $predicate)
                        || ! is_scalar($predicate['value'])
                    ) {
                        throw $this->invalid(
                            $form,
                            "layout.conditions.{$index}.when.{$predicateIndex}.value must be scalar for operator [{$operator}].",
                        );
                    }

                    $normalizedPredicate['value'] = $predicate['value'];
                }

                $normalizedWhen[] = $normalizedPredicate;
            }

            $resolved[] = [
                'targets' => array_values(array_unique($normalizedTargets)),
                'match' => $match,
                'when' => $normalizedWhen,
            ];
        }

        return $resolved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultBlocks(PublishedForm $form): array
    {
        $blocks = [];
        $seenKeys = [];

        foreach ($form->schema['sections'] as $section) {
            $label = $section['label'] ?? null;

            if (is_string($label) && trim($label) !== '') {
                $baseKey = (string) ($section['key'] ?? 'section').'_section';
                $key = $baseKey;
                $suffix = 2;

                while (isset($seenKeys[$key]) || $form->field($key) !== null) {
                    $key = $baseKey.'_'.$suffix++;
                }

                $seenKeys[$key] = true;
                $blocks[] = [
                    'type' => 'content',
                    'key' => $key,
                    'variant' => 'section',
                    'text' => trim($label),
                ];
            }

            foreach ($section['fields'] as $field) {
                if (($field['type'] ?? null) === 'hidden') {
                    continue;
                }

                $key = (string) $field['key'];
                $seenKeys[$key] = true;
                $blocks[] = [
                    'type' => 'field',
                    'key' => $key,
                    'field' => $key,
                ];
            }
        }

        return $blocks;
    }

    /**
     * @return array{heading: string, message: string}
     */
    private function success(PublishedForm $form, mixed $value): array
    {
        if ($value === null) {
            $value = [];
        }

        if (! is_array($value)) {
            throw $this->invalid($form, 'settings.public.hosted.success must be an array.');
        }

        return [
            'heading' => $this->stringSetting(
                $form,
                $value['heading'] ?? 'Thank you',
                'settings.public.hosted.success.heading',
                255,
            ),
            'message' => $this->stringSetting(
                $form,
                $value['message'] ?? 'Your information has been received.',
                'settings.public.hosted.success.message',
                2000,
            ),
        ];
    }

    /**
     * @return array{logo_url: string|null, primary_color: string, accent_color: string}
     */
    private function branding(PublishedForm $form, mixed $value): array
    {
        if ($value === null) {
            $value = [];
        }

        if (! is_array($value)) {
            throw $this->invalid($form, 'settings.public.hosted.branding must be an array.');
        }

        $logoUrl = $value['logo_url'] ?? null;

        if ($logoUrl !== null) {
            if (! is_string($logoUrl)
                || trim($logoUrl) === ''
                || filter_var(trim($logoUrl), FILTER_VALIDATE_URL) === false
                || ! in_array(parse_url(trim($logoUrl), PHP_URL_SCHEME), ['http', 'https'], true)
            ) {
                throw $this->invalid(
                    $form,
                    'settings.public.hosted.branding.logo_url must be an absolute http/https URL or null.',
                );
            }

            $logoUrl = trim($logoUrl);
        }

        return [
            'logo_url' => $logoUrl,
            'primary_color' => $this->color(
                $form,
                $value['primary_color']
                    ?? config('public_surfaces.theme.colors.primary')
                    ?? '#0f1630',
                'settings.public.hosted.branding.primary_color',
            ),
            'accent_color' => $this->color(
                $form,
                $value['accent_color']
                    ?? config('public_surfaces.theme.colors.accent')
                    ?? '#6da0d3',
                'settings.public.hosted.branding.accent_color',
            ),
        ];
    }

    private function color(PublishedForm $form, mixed $value, string $path): string
    {
        if (! is_string($value)
            || preg_match('/^#[0-9a-fA-F]{6}$/D', trim($value)) !== 1
        ) {
            throw $this->invalid($form, "{$path} must be a six-digit hex color.");
        }

        return strtolower(trim($value));
    }

    private function blockKey(PublishedForm $form, mixed $value, string $path): string
    {
        if (! is_string($value)
            || preg_match(self::BLOCK_KEY_PATTERN, trim($value)) !== 1
        ) {
            throw $this->invalid(
                $form,
                "{$path} must use lowercase snake_case and begin with a letter.",
            );
        }

        return trim($value);
    }

    private function stringSetting(
        PublishedForm $form,
        mixed $value,
        string $path,
        int $maximumLength,
    ): string {
        if (! is_string($value)) {
            throw $this->invalid($form, "{$path} must be a string.");
        }

        $value = trim($value);

        if ($value === '') {
            throw $this->invalid($form, "{$path} cannot be empty.");
        }

        if (mb_strlen($value) > $maximumLength) {
            throw $this->invalid(
                $form,
                "{$path} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function invalid(PublishedForm $form, string $message): DomainException
    {
        return new DomainException(
            "Published form [{$form->key}] has invalid hosted layout: {$message}",
        );
    }
}