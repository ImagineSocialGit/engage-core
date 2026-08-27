<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionValue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class FormSubmissionReadService
{
    /**
     * @return array{
     *     id: int,
     *     key: string,
     *     name: string,
     *     description: string|null
     * }
     */
    public function formSummary(FormDefinition $formDefinition): array
    {
        return [
            'id' => (int) $formDefinition->getKey(),
            'key' => $formDefinition->key,
            'name' => $formDefinition->name,
            'description' => $formDefinition->description,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forForm(FormDefinition $formDefinition): LengthAwarePaginator
    {
        return FormSubmission::query()
            ->where('form_definition_id', $formDefinition->getKey())
            ->with(['contact', 'formVersion'])
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(50)
            ->through(fn (FormSubmission $submission): array => $this->summary($submission));
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(FormSubmission $submission): array
    {
        $submission->load([
            'formDefinition',
            'formVersion',
            'contact',
            'values' => fn ($query) => $query
                ->orderBy('sort_order')
                ->orderBy('id'),
        ]);

        $verification = data_get(
            $submission->meta,
            FormSubmissionInput::INTERNAL_META_KEY.'.verification',
        );

        return [
            ...$this->summary($submission),
            'form' => [
                'key' => $submission->formDefinition->key,
                'name' => $submission->formDefinition->name,
            ],
            'contact' => $submission->contact === null
                ? null
                : [
                    'id' => (int) $submission->contact->getKey(),
                    'name' => $this->contactName($submission->contact),
                    'email' => $submission->contact->email,
                    'phone' => $submission->contact->phone,
                ],
            'values' => $submission->values
                ->map(fn (FormSubmissionValue $value): array => [
                    'key' => $value->field_key,
                    'label' => $value->field_label ?: $value->field_key,
                    'type' => $value->field_type,
                    'display_value' => $this->displayValue($value),
                ])
                ->values()
                ->all(),
            'consents' => $this->consents($submission),
            'verification' => is_array($verification)
                ? [
                    'provider' => $verification['provider'] ?? null,
                    'outcome' => $verification['outcome'] ?? null,
                    'verified_at' => $verification['verified_at'] ?? null,
                    'hostname' => $verification['hostname'] ?? null,
                    'action' => $verification['action'] ?? null,
                ]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(FormSubmission $submission): array
    {
        return [
            'id' => (int) $submission->getKey(),
            'status' => $submission->status,
            'review_status' => $submission->review_status,
            'submitted_at' => $submission->submitted_at,
            'reviewed_at' => $submission->reviewed_at,
            'source' => $submission->source,
            'provider' => $submission->provider,
            'version' => $submission->formVersion !== null
                ? (int) $submission->formVersion->version
                : null,
            'contact_name' => $submission->contact !== null
                ? $this->contactName($submission->contact)
                : null,
            'contact_email' => $submission->contact?->email,
        ];
    }

    /**
     * @return array<int, array{
     *     field: string,
     *     label: string,
     *     channel: string,
     *     purpose: string,
     *     accepted: bool
     * }>
     */
    private function consents(FormSubmission $submission): array
    {
        $configured = data_get(
            $submission->formVersion?->settings,
            'submission.consents',
            [],
        );

        if (! is_array($configured)) {
            return [];
        }

        /** @var Collection<string, FormSubmissionValue> $values */
        $values = $submission->values->keyBy('field_key');
        $consents = [];

        foreach ($configured as $consent) {
            if (! is_array($consent)) {
                continue;
            }

            $field = $consent['field'] ?? null;
            $channel = $consent['channel'] ?? null;
            $purpose = $consent['purpose'] ?? null;

            if (! is_string($field)
                || ! is_string($channel)
                || ! is_string($purpose)
            ) {
                continue;
            }

            $value = $values->get($field);

            $consents[] = [
                'field' => $field,
                'label' => $value?->field_label ?: str($field)
                    ->replace('_', ' ')
                    ->title()
                    ->toString(),
                'channel' => $channel,
                'purpose' => $purpose,
                'accepted' => $value instanceof FormSubmissionValue
                    ? $this->booleanValue($value)
                    : false,
            ];
        }

        return $consents;
    }

    private function booleanValue(FormSubmissionValue $value): bool
    {
        if ($value->value_boolean !== null) {
            return (bool) $value->value_boolean;
        }

        return (bool) data_get($value->value, 'value', false);
    }

    private function displayValue(FormSubmissionValue $value): string
    {
        if ($value->value_boolean !== null) {
            return $value->value_boolean ? 'Yes' : 'No';
        }

        if ($value->value_datetime !== null) {
            return $value->value_datetime->toAtomString();
        }

        if ($value->value_date !== null) {
            return $value->value_date->toDateString();
        }

        if ($value->value_text !== null && $value->value_text !== '') {
            return $value->value_text;
        }

        if ($value->value_number !== null) {
            return (string) $value->value_number;
        }

        $raw = data_get($value->value, 'value');

        if (is_array($raw)) {
            return implode(', ', array_map(
                static fn (mixed $item): string => (string) $item,
                $raw,
            ));
        }

        if ($raw === null || $raw === '') {
            return '—';
        }

        return (string) $raw;
    }

    private function contactName(object $contact): string
    {
        foreach ([$contact->name, trim($contact->first_name.' '.$contact->last_name), $contact->email] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Contact #'.$contact->getKey();
    }
}