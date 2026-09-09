<?php

namespace App\Modules\InboundMessaging\Services\Email;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;

final class InboundEmailContactExtractionAssistant
{
    /**
     * @var array<string, array<int, string>>
     */
    private const LABEL_ALIASES = [
        'email' => [
            'Email',
            'Email Address',
            'E-mail',
            'E-mail Address',
        ],
        'first_name' => [
            'First Name',
            'First',
            'Given Name',
        ],
        'last_name' => [
            'Last Name',
            'Last',
            'Surname',
            'Family Name',
        ],
        'name' => [
            'Full Name',
            'Contact Name',
            'Name',
        ],
        'phone' => [
            'Phone',
            'Phone Number',
            'Mobile',
            'Mobile Phone',
            'Cell',
            'Cell Phone',
        ],
    ];

    public function __construct(
        private readonly InboundEmailSampleParser $sampleParser,
        private readonly InboundEmailContactExtractor $extractor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function assist(InboundEmailRoute $route, string $raw): array
    {
        $sample = $this->sampleParser->parse($raw);
        $definition = $this->suggestedDefinition($sample);
        $test = $this->extractor->extract(
            source: [
                'sender_email' => $sample['from'],
                'reply_to_email' => $sample['reply_to'],
                'subject' => $sample['subject'],
                'body' => $sample['body'],
            ],
            definition: $definition,
        );

        return [
            'route_id' => (int) $route->getKey(),
            'sample' => $sample,
            'suggested_definition' => $definition,
            'suggestion_rows' => $this->suggestionRows($definition),
            'test' => $test,
        ];
    }

    /**
     * @param array<string, mixed> $sample
     * @return array<string, mixed>
     */
    private function suggestedDefinition(array $sample): array
    {
        $fields = [];

        foreach ($this->extractor->targetKeys() as $target) {
            $labeled = $this->labeledSuggestion(
                target: $target,
                subject: $this->nullableString($sample['subject'] ?? null),
                body: $this->nullableString($sample['body'] ?? null),
            );

            if ($labeled !== null) {
                $fields[$target] = $labeled;

                continue;
            }

            if ($target === 'email') {
                $replyTo = $this->emailAddress($sample['reply_to'] ?? null);
                $sender = $this->emailAddress($sample['from'] ?? null);

                if ($replyTo !== null) {
                    $fields[$target] = [
                        'source' => InboundEmailContactExtractor::SOURCE_REPLY_TO_EMAIL,
                        'label' => null,
                    ];

                    continue;
                }

                if ($sender !== null) {
                    $fields[$target] = [
                        'source' => InboundEmailContactExtractor::SOURCE_SENDER_EMAIL,
                        'label' => null,
                    ];
                }
            }
        }

        return $this->extractor->normalizeDefinition([
            'version' => InboundEmailContactExtractor::VERSION,
            'fields' => $fields,
            'required_fields' => ['email'],
        ]);
    }

    /**
     * @return array{source: string, label: string}|null
     */
    private function labeledSuggestion(
        string $target,
        ?string $subject,
        ?string $body,
    ): ?array {
        foreach (self::LABEL_ALIASES[$target] ?? [] as $label) {
            if ($body !== null && $this->containsLabeledValue($body, $label, true)) {
                return [
                    'source' => InboundEmailContactExtractor::SOURCE_BODY_AFTER_LABEL,
                    'label' => $label,
                ];
            }
        }

        foreach (self::LABEL_ALIASES[$target] ?? [] as $label) {
            if ($subject !== null && $this->containsLabeledValue($subject, $label, false)) {
                return [
                    'source' => InboundEmailContactExtractor::SOURCE_SUBJECT_AFTER_LABEL,
                    'label' => $label,
                ];
            }
        }

        return null;
    }

    private function containsLabeledValue(
        string $value,
        string $label,
        bool $multiline,
    ): bool {
        $labelPattern = preg_quote($label, '/');

        if (preg_match(
            '/(?:^|\R)\s*'.$labelPattern.'\s*(?::|-)\s*\S[^\r\n]*/iu',
            $value,
        ) === 1) {
            return true;
        }

        if (! $multiline) {
            return preg_match(
                '/'.$labelPattern.'\s*(?::|-)\s*\S.+$/iu',
                $value,
            ) === 1;
        }

        $lines = preg_split('/\R/u', $value) ?: [];

        foreach ($lines as $index => $line) {
            $lineLabel = preg_replace(
                '/\s*(?::|-)\s*$/u',
                '',
                trim((string) $line),
            ) ?? trim((string) $line);

            if (mb_strtolower($lineLabel) !== mb_strtolower($label)) {
                continue;
            }

            for ($next = $index + 1; $next < count($lines); $next++) {
                if ($this->nullableString($lines[$next] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array{field: string, source: string}>
     */
    private function suggestionRows(array $definition): array
    {
        $labels = $this->extractor->targetLabels();
        $rows = [];

        foreach ($definition['fields'] ?? [] as $target => $field) {
            if (! is_array($field)) {
                continue;
            }

            $source = (string) ($field['source'] ?? '');
            $marker = $this->nullableString($field['label'] ?? null);
            $sourceLabel = $this->extractor->sourceOptions((string) $target)[$source]
                ?? $source;

            if ($marker !== null) {
                $sourceLabel .= ' "'.$marker.'"';
            }

            $rows[] = [
                'field' => $labels[$target] ?? (string) $target,
                'source' => $sourceLabel,
            ];
        }

        return $rows;
    }

    private function emailAddress(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/<([^<>]+)>/', $value, $matches) === 1) {
            $value = trim((string) ($matches[1] ?? ''));
        }

        if (preg_match(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            $value,
            $matches,
        ) === 1) {
            $value = trim((string) ($matches[0] ?? ''));
        }

        $value = mb_strtolower($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? $value
            : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}