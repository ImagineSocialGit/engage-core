<?php

namespace Database\Factories;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormSubmissionValue>
 */
class FormSubmissionValueFactory extends Factory
{
    protected $model = FormSubmissionValue::class;

    public function definition(): array
    {
        return [
            'form_submission_id' => FormSubmission::factory(),
            'field_key' => 'notes',
            'field_label' => 'Notes',
            'field_type' => 'textarea',
            'value' => ['value' => 'Example answer'],
            'value_text' => 'Example answer',
            'value_number' => null,
            'value_boolean' => null,
            'value_date' => null,
            'value_datetime' => null,
            'sort_order' => 10,
            'meta' => null,
        ];
    }

    public function boolean(string $fieldKey, bool $value): self
    {
        return $this->state([
            'field_key' => $fieldKey,
            'field_type' => 'boolean',
            'value' => ['value' => $value],
            'value_text' => $value ? 'Yes' : 'No',
            'value_boolean' => $value,
        ]);
    }

    public function number(string $fieldKey, float|int $value): self
    {
        return $this->state([
            'field_key' => $fieldKey,
            'field_type' => 'number',
            'value' => ['value' => $value],
            'value_text' => (string) $value,
            'value_number' => $value,
        ]);
    }
}
