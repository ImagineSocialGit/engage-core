<?php

namespace App\Modules\Webinars\Services;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

class WebinarRegistrationQuestionResolver
{
    public const TYPE_SELECT = 'select';

    private const MAX_QUESTIONS = 20;

    private const MAX_OPTIONS = 50;

    private const DEFAULT_OTHER_MAX_LENGTH = 500;

    private const MAX_OTHER_MAX_LENGTH = 2000;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolve(mixed $definitions): array
    {
        if ($definitions === null || $definitions === []) {
            return [];
        }

        if (! is_array($definitions) || ! array_is_list($definitions)) {
            throw new InvalidArgumentException(
                'Webinar registration questions must be configured as a list.',
            );
        }

        if (count($definitions) > self::MAX_QUESTIONS) {
            throw new InvalidArgumentException(sprintf(
                'Webinar registration questions may contain no more than %d definitions.',
                self::MAX_QUESTIONS,
            ));
        }

        $questions = [];
        $seenQuestionKeys = [];

        foreach ($definitions as $index => $definition) {
            if (! is_array($definition)) {
                throw new InvalidArgumentException(sprintf(
                    'Webinar registration question at index [%d] must be an array.',
                    $index,
                ));
            }

            $question = $this->normalizeQuestion($definition, $index);
            $key = $question['key'];

            if (isset($seenQuestionKeys[$key])) {
                throw new InvalidArgumentException(
                    "Webinar registration question key [{$key}] is duplicated.",
                );
            }

            $seenQuestionKeys[$key] = true;
            $questions[] = $question;
        }

        return $questions;
    }

    public function normalizeSubmittedAnswers(mixed $answers): mixed
    {
        if (! is_array($answers)) {
            return $answers;
        }

        $normalized = [];

        foreach ($answers as $questionKey => $answer) {
            if (! is_array($answer)) {
                $normalized[$questionKey] = $answer;

                continue;
            }

            $normalized[$questionKey] = $answer;

            foreach (['answer', 'other'] as $field) {
                if (! array_key_exists($field, $answer)) {
                    continue;
                }

                $value = $answer[$field];

                if (! is_string($value)) {
                    continue;
                }

                $value = trim($value);
                $normalized[$questionKey][$field] = $value !== '' ? $value : null;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function validationRules(array $questions, mixed $submittedAnswers): array
    {
        if ($questions === []) {
            return [
                'registration_questions' => ['prohibited'],
            ];
        }

        $submittedAnswers = is_array($submittedAnswers)
            ? $submittedAnswers
            : [];
        $questionKeys = array_column($questions, 'key');
        $hasRequiredQuestion = collect($questions)
            ->contains(fn (array $question): bool => $question['required'] === true);
        $rules = [
            'registration_questions' => [
                Rule::requiredIf($hasRequiredQuestion),
                'nullable',
                'array:'.implode(',', $questionKeys),
            ],
        ];

        foreach ($questions as $question) {
            $key = $question['key'];
            $path = "registration_questions.{$key}";
            $selectedAnswer = data_get($submittedAnswers, "{$key}.answer");
            $other = is_array($question['other'] ?? null)
                ? $question['other']
                : null;
            $otherSelected = $other !== null
                && is_string($selectedAnswer)
                && hash_equals($other['option_key'], $selectedAnswer);

            $rules[$path] = [
                Rule::requiredIf($question['required'] === true),
                'nullable',
                'array:answer,other',
            ];
            $rules["{$path}.answer"] = [
                Rule::requiredIf($question['required'] === true),
                'nullable',
                'string',
                Rule::in(array_column($question['options'], 'key')),
            ];

            if ($other === null) {
                $rules["{$path}.other"] = ['prohibited'];

                continue;
            }

            $rules["{$path}.other"] = [
                Rule::requiredIf($otherSelected && $other['required'] === true),
                Rule::prohibitedIf(! $otherSelected),
                'nullable',
                'string',
                'max:'.$other['max_length'],
            ];
        }

        return $rules;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, string>
     */
    public function validationMessages(array $questions): array
    {
        $messages = [];

        foreach ($questions as $question) {
            $key = $question['key'];
            $label = $question['label'];
            $path = "registration_questions.{$key}";

            $messages["{$path}.required"] = "Answer the question: {$label}";
            $messages["{$path}.answer.required"] = "Choose an answer for: {$label}";
            $messages["{$path}.answer.in"] = 'Choose one of the available answers.';

            if (! is_array($question['other'] ?? null)) {
                continue;
            }

            $messages["{$path}.other.required"] = 'Tell us a little more about your answer.';
            $messages["{$path}.other.max"] = sprintf(
                'Your additional answer may not be longer than %d characters.',
                $question['other']['max_length'],
            );
        }

        return $messages;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @param array<string, mixed> $submittedAnswers
     * @return array<int, array<string, mixed>>
     */
    public function responseSnapshots(
        array $questions,
        array $submittedAnswers,
    ): array {
        $questionsByKey = collect($questions)->keyBy('key');
        $unknownQuestionKeys = array_values(array_diff(
            array_keys($submittedAnswers),
            $questionsByKey->keys()->all(),
        ));

        if ($unknownQuestionKeys !== []) {
            throw new InvalidArgumentException(sprintf(
                'Submitted Webinar registration question [%s] is not configured.',
                $unknownQuestionKeys[0],
            ));
        }

        $snapshots = [];

        foreach ($questions as $question) {
            $submitted = $submittedAnswers[$question['key']] ?? null;

            if (! is_array($submitted)) {
                if ($question['required'] === true) {
                    throw new InvalidArgumentException(
                        "Required Webinar registration question [{$question['key']}] was not answered.",
                    );
                }

                continue;
            }

            $answerKey = $this->nullableString($submitted['answer'] ?? null);

            if ($answerKey === null) {
                if ($question['required'] === true) {
                    throw new InvalidArgumentException(
                        "Required Webinar registration question [{$question['key']}] was not answered.",
                    );
                }

                continue;
            }

            $option = collect($question['options'])
                ->first(fn (array $option): bool => $option['key'] === $answerKey);

            if (! is_array($option)) {
                throw new InvalidArgumentException(
                    "Webinar registration question [{$question['key']}] received an invalid answer.",
                );
            }

            $other = is_array($question['other'] ?? null)
                ? $question['other']
                : null;
            $otherSelected = $other !== null
                && $other['option_key'] === $answerKey;
            $answerText = $this->nullableString($submitted['other'] ?? null);

            if (! $otherSelected) {
                $answerText = null;
            } elseif ($other['required'] === true && $answerText === null) {
                throw new InvalidArgumentException(
                    "Webinar registration question [{$question['key']}] requires an additional answer.",
                );
            }

            if ($answerText !== null && mb_strlen($answerText) > $other['max_length']) {
                throw new InvalidArgumentException(
                    "Webinar registration question [{$question['key']}] additional answer is too long.",
                );
            }

            $snapshots[] = [
                'question_key' => $question['key'],
                'question_label' => $question['label'],
                'question_type' => $question['type'],
                'answer_key' => $answerKey,
                'answer_label' => $option['label'],
                'answer_text' => $answerText,
                'definition_version' => $question['definition_version'],
                'sort_order' => $question['sort_order'],
            ];
        }

        return $snapshots;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function normalizeQuestion(array $definition, int $index): array
    {
        $key = $this->requiredKey(
            $definition['key'] ?? null,
            "Webinar registration question at index [{$index}] key",
        );
        $label = $this->requiredString(
            $definition['label'] ?? null,
            "Webinar registration question [{$key}] label",
            255,
        );
        $type = $this->nullableString($definition['type'] ?? null)
            ?? self::TYPE_SELECT;

        if ($type !== self::TYPE_SELECT) {
            throw new InvalidArgumentException(
                "Webinar registration question [{$key}] type [{$type}] is not supported.",
            );
        }

        $options = $this->normalizeOptions(
            $definition['options'] ?? null,
            $key,
        );
        $other = $this->normalizeOther(
            $definition['other'] ?? null,
            $key,
            $options,
        );

        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => ($definition['required'] ?? false) === true,
            'placeholder' => $this->nullableString($definition['placeholder'] ?? null)
                ?? 'Select an option',
            'helper' => $this->nullableString($definition['helper'] ?? null),
            'definition_version' => $this->definitionVersion(
                $definition['definition_version'] ?? 1,
                $key,
            ),
            'options' => $options,
            'other' => $other,
            'sort_order' => ($index + 1) * 10,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function normalizeOptions(mixed $definitions, string $questionKey): array
    {
        if (! is_array($definitions) || ! array_is_list($definitions) || $definitions === []) {
            throw new InvalidArgumentException(
                "Webinar registration question [{$questionKey}] must define at least one option.",
            );
        }

        if (count($definitions) > self::MAX_OPTIONS) {
            throw new InvalidArgumentException(sprintf(
                'Webinar registration question [%s] may contain no more than %d options.',
                $questionKey,
                self::MAX_OPTIONS,
            ));
        }

        $options = [];
        $seenOptionKeys = [];

        foreach ($definitions as $index => $definition) {
            if (! is_array($definition)) {
                throw new InvalidArgumentException(sprintf(
                    'Webinar registration question [%s] option at index [%d] must be an array.',
                    $questionKey,
                    $index,
                ));
            }

            $key = $this->requiredKey(
                $definition['key'] ?? null,
                "Webinar registration question [{$questionKey}] option at index [{$index}] key",
            );

            if (isset($seenOptionKeys[$key])) {
                throw new InvalidArgumentException(
                    "Webinar registration question [{$questionKey}] option key [{$key}] is duplicated.",
                );
            }

            $seenOptionKeys[$key] = true;
            $options[] = [
                'key' => $key,
                'label' => $this->requiredString(
                    $definition['label'] ?? null,
                    "Webinar registration question [{$questionKey}] option [{$key}] label",
                    255,
                ),
            ];
        }

        return $options;
    }

    /**
     * @param array<int, array{key: string, label: string}> $options
     * @return array<string, mixed>|null
     */
    private function normalizeOther(
        mixed $definition,
        string $questionKey,
        array $options,
    ): ?array {
        if ($definition === null || $definition === false) {
            return null;
        }

        if (! is_array($definition)) {
            throw new InvalidArgumentException(
                "Webinar registration question [{$questionKey}] other configuration must be an array.",
            );
        }

        $optionKey = $this->requiredKey(
            $definition['option_key'] ?? null,
            "Webinar registration question [{$questionKey}] other option_key",
        );

        if (! collect($options)->contains(
            fn (array $option): bool => $option['key'] === $optionKey,
        )) {
            throw new InvalidArgumentException(
                "Webinar registration question [{$questionKey}] other option_key [{$optionKey}] is not a configured option.",
            );
        }

        $maxLength = $definition['max_length'] ?? self::DEFAULT_OTHER_MAX_LENGTH;

        if (! is_int($maxLength)
            || $maxLength < 1
            || $maxLength > self::MAX_OTHER_MAX_LENGTH
        ) {
            throw new InvalidArgumentException(sprintf(
                'Webinar registration question [%s] other max_length must be between 1 and %d.',
                $questionKey,
                self::MAX_OTHER_MAX_LENGTH,
            ));
        }

        return [
            'option_key' => $optionKey,
            'label' => $this->nullableString($definition['label'] ?? null)
                ?? 'Tell us more',
            'placeholder' => $this->nullableString($definition['placeholder'] ?? null),
            'required' => ($definition['required'] ?? true) === true,
            'max_length' => $maxLength,
        ];
    }

    private function requiredKey(mixed $value, string $field): string
    {
        $value = $this->requiredString($value, $field, 100);

        if (preg_match('/^[a-z][a-z0-9_]{0,99}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "{$field} must use lowercase snake_case and begin with a letter.",
            );
        }

        return $value;
    }

    private function requiredString(
        mixed $value,
        string $field,
        int $maxLength,
    ): string {
        $value = $this->nullableString($value);

        if ($value === null) {
            throw new InvalidArgumentException("Missing required {$field}.");
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                "{$field} may not be longer than {$maxLength} characters.",
            );
        }

        return $value;
    }

    private function definitionVersion(mixed $value, string $questionKey): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        $value = $this->nullableString($value);

        if ($value === null || mb_strlen($value) > 50) {
            throw new InvalidArgumentException(
                "Webinar registration question [{$questionKey}] definition_version must be a non-empty value no longer than 50 characters.",
            );
        }

        return $value;
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