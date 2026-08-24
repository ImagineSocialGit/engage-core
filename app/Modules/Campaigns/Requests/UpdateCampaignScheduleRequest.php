<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Messaging\Models\MessageChainStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateCampaignScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_chain_version_id' => ['required', 'integer', 'min:1'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*' => ['required', 'array'],
            'steps.*.key' => ['required', 'string', 'max:191', 'distinct'],
            'steps.*.name' => ['nullable', 'string', 'max:255'],
            'steps.*.position' => ['required', 'integer', 'min:1', 'max:1000', 'distinct'],
            'steps.*.timing_type' => ['required', 'string', Rule::in([
                MessageChainStep::TIMING_IMMEDIATE,
                MessageChainStep::TIMING_DELAY,
                'preserve',
            ])],
            'steps.*.delay_value' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'steps.*.delay_unit' => ['nullable', 'string', Rule::in(['seconds', 'minutes', 'hours', 'days'])],
            'steps.*.remove' => ['nullable', 'boolean'],
            'new_step' => ['nullable', 'array'],
            'new_step.add' => ['nullable', 'boolean'],
            'new_step.message_template_preset_id' => [
                'nullable',
                'integer',
                Rule::requiredIf($this->boolean('new_step.add')),
                'exists:message_template_presets,id',
            ],
            'new_step.name' => ['nullable', 'string', 'max:255'],
            'new_step.position' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'new_step.timing_type' => ['nullable', 'string', Rule::in([
                MessageChainStep::TIMING_IMMEDIATE,
                MessageChainStep::TIMING_DELAY,
            ])],
            'new_step.delay_value' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'new_step.delay_unit' => ['nullable', 'string', Rule::in(['seconds', 'minutes', 'hours', 'days'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('steps', []) as $index => $step) {
                if (! is_array($step)
                    || ($step['timing_type'] ?? null) !== MessageChainStep::TIMING_DELAY
                    || filter_var($step['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ) {
                    continue;
                }

                if (! is_numeric($step['delay_value'] ?? null)) {
                    $validator->errors()->add(
                        "steps.{$index}.delay_value",
                        'Choose how long to wait before this message step.',
                    );
                }

                if (! in_array($step['delay_unit'] ?? null, ['seconds', 'minutes', 'hours', 'days'], true)) {
                    $validator->errors()->add(
                        "steps.{$index}.delay_unit",
                        'Choose seconds, minutes, hours, or days.',
                    );
                } elseif ($this->delaySeconds($step) > 315360000) {
                    $validator->errors()->add(
                        "steps.{$index}.delay_value",
                        'A Campaign wait cannot exceed ten years.',
                    );
                }
            }

            if ($this->boolean('new_step.add')
                && $this->input('new_step.timing_type') === MessageChainStep::TIMING_DELAY
            ) {
                if (! is_numeric($this->input('new_step.delay_value'))) {
                    $validator->errors()->add(
                        'new_step.delay_value',
                        'Choose how long to wait before the new message.',
                    );
                }

                if (! in_array($this->input('new_step.delay_unit'), ['seconds', 'minutes', 'hours', 'days'], true)) {
                    $validator->errors()->add(
                        'new_step.delay_unit',
                        'Choose seconds, minutes, hours, or days.',
                    );
                } elseif ($this->delaySeconds((array) $this->input('new_step', [])) > 315360000) {
                    $validator->errors()->add(
                        'new_step.delay_value',
                        'A Campaign wait cannot exceed ten years.',
                    );
                }
            }

            $kept = collect($this->input('steps', []))
                ->contains(fn (mixed $step): bool => is_array($step)
                    && ! filter_var($step['remove'] ?? false, FILTER_VALIDATE_BOOLEAN));

            if (! $kept && ! $this->boolean('new_step.add')) {
                $validator->errors()->add(
                    'steps',
                    'A Campaign schedule must keep at least one message step.',
                );
            }
        });
    }

    public function expectedVersionId(): int
    {
        return (int) $this->validated('message_chain_version_id');
    }

    /** @return array<int, array<string, mixed>> */
    public function scheduleSteps(): array
    {
        return array_values($this->validated('steps'));
    }

    /** @return array<string, mixed>|null */
    public function newStep(): ?array
    {
        if (! $this->boolean('new_step.add')) {
            return null;
        }

        $step = $this->validated('new_step', []);

        return is_array($step) ? $step : null;
    }

    /** @param array<string, mixed> $input */
    private function delaySeconds(array $input): int
    {
        $value = max(0, (int) ($input['delay_value'] ?? 0));

        return $value * match ($input['delay_unit'] ?? null) {
            'seconds' => 1,
            'minutes' => 60,
            'hours' => 3600,
            'days' => 86400,
            default => 0,
        };
    }
}