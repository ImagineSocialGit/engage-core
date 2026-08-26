<?php

namespace App\Modules\Core\Requests;

use App\Modules\Core\Models\BusinessCalendarExclusion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBusinessCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', Rule::in(['routes', 'settings'])],
            'skipped_weekdays' => ['sometimes', 'array', 'max:6'],
            'skipped_weekdays.*' => ['integer', 'distinct', 'between:1,7'],
            'exclusions' => ['sometimes', 'array', 'max:366'],
            'exclusions.*.key' => ['nullable', 'uuid'],
            'exclusions.*.name' => ['required', 'string', 'max:255'],
            'exclusions.*.recurrence' => [
                'required',
                Rule::in(BusinessCalendarExclusion::RECURRENCES),
            ],
            'exclusions.*.exact_date' => [
                'nullable',
                'required_if:exclusions.*.recurrence,'.BusinessCalendarExclusion::RECURRENCE_ONCE,
                'date_format:Y-m-d',
            ],
            'exclusions.*.month' => [
                'nullable',
                'required_if:exclusions.*.recurrence,'.BusinessCalendarExclusion::RECURRENCE_ANNUAL,
                'integer',
                'between:1,12',
            ],
            'exclusions.*.day' => [
                'nullable',
                'required_if:exclusions.*.recurrence,'.BusinessCalendarExclusion::RECURRENCE_ANNUAL,
                'integer',
                'between:1,31',
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $seen = [];

            foreach ((array) $this->input('exclusions', []) as $index => $exclusion) {
                if (! is_array($exclusion)) {
                    continue;
                }

                $recurrence = (string) ($exclusion['recurrence'] ?? '');
                $identity = null;

                if ($recurrence === BusinessCalendarExclusion::RECURRENCE_ANNUAL) {
                    $month = (int) ($exclusion['month'] ?? 0);
                    $day = (int) ($exclusion['day'] ?? 0);

                    if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                        $date = \DateTimeImmutable::createFromFormat(
                            '!Y-n-j',
                            "2000-{$month}-{$day}",
                        );

                        if (! $date || (int) $date->format('n') !== $month || (int) $date->format('j') !== $day) {
                            $validator->errors()->add(
                                "exclusions.{$index}.day",
                                'Choose a valid month and day.',
                            );
                        }
                    }

                    $identity = "annual:{$month}:{$day}";
                }

                if ($recurrence === BusinessCalendarExclusion::RECURRENCE_ONCE) {
                    $identity = 'once:'.(string) ($exclusion['exact_date'] ?? '');
                }

                if ($identity !== null && isset($seen[$identity])) {
                    $validator->errors()->add(
                        "exclusions.{$index}.name",
                        'That date is already listed.',
                    );
                }

                if ($identity !== null) {
                    $seen[$identity] = true;
                }
            }
        }];
    }
}