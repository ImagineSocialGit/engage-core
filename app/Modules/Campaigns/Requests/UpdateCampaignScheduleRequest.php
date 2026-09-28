<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Requests\Concerns\InteractsWithMessageMediaAuthoring;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateCampaignScheduleRequest extends FormRequest
{
    use InteractsWithMessageMediaAuthoring;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
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
            'new_step.template_mode' => [
                'nullable',
                Rule::in(['create', 'existing']),
            ],
            'new_step.message_template_preset_id' => [
                Rule::excludeIf(! $this->boolean('new_step.add')
                    || $this->newTemplateMode() !== 'existing'),
                'nullable',
                'integer',
                Rule::requiredIf($this->boolean('new_step.add')
                    && $this->newTemplateMode() === 'existing'),
                'exists:message_template_presets,id',
            ],
            'new_step.template.name' => [
                Rule::excludeIf(! $this->creatingTemplate()),
                Rule::requiredIf($this->creatingTemplate()),
                'nullable', 'string', 'max:191',
            ],
            'new_step.template.channel' => [
                Rule::excludeIf(! $this->creatingTemplate()),
                Rule::requiredIf($this->creatingTemplate()),
                'nullable', Rule::in(['email', 'sms']),
            ],
            'new_step.template.subject' => [
                Rule::excludeIf(! $this->creatingTemplate() || $this->newTemplateChannel() !== 'email'),
                Rule::requiredIf($this->creatingTemplate() && $this->newTemplateChannel() === 'email'),
                'nullable', 'string', 'max:255',
            ],
            'new_step.template.body' => [
                Rule::excludeIf(! $this->creatingTemplate() || $this->newTemplateChannel() !== 'email'),
                Rule::requiredIf($this->creatingTemplate() && $this->newTemplateChannel() === 'email'),
                'nullable', 'string', 'max:10000',
            ],
            'new_step.template.message' => [
                Rule::excludeIf(! $this->creatingTemplate() || $this->newTemplateChannel() !== 'sms'),
                Rule::requiredIf($this->creatingTemplate() && $this->newTemplateChannel() === 'sms'),
                'nullable', 'string', 'max:1600',
            ],
            'new_step.name' => ['nullable', 'string', 'max:255'],
            'new_step.position' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'new_step.timing_type' => ['nullable', 'string', Rule::in([
                MessageChainStep::TIMING_IMMEDIATE,
                MessageChainStep::TIMING_DELAY,
            ])],
            'new_step.delay_value' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'new_step.delay_unit' => ['nullable', 'string', Rule::in(['seconds', 'minutes', 'hours', 'days'])],
            'extend_in_progress' => ['nullable', 'boolean'],
        ], $this->creatingTemplate() && $this->newTemplateChannel() === 'email'
            ? $this->messageMediaRules()
            : []);
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

            if ($this->boolean('extend_in_progress') && ! $this->boolean('new_step.add')) {
                $validator->errors()->add(
                    'extend_in_progress',
                    'Add a final message to extend current Campaign participants.',
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

    public function extendInProgress(): bool
    {
        return $this->boolean('extend_in_progress');
    }

    public function creatingTemplate(): bool
    {
        return $this->boolean('new_step.add')
            && $this->newTemplateMode() === 'create';
    }

    private function newTemplateMode(): string
    {
        $mode = $this->input('new_step.template_mode');

        if (in_array($mode, ['create', 'existing'], true)) {
            return $mode;
        }

        return $this->filled('new_step.message_template_preset_id')
            ? 'existing'
            : 'create';
    }

    public function newTemplateChannel(): string
    {
        return $this->input('new_step.template.channel') === 'sms' ? 'sms' : 'email';
    }

    public function newTemplateName(): string
    {
        return trim((string) $this->validated('new_step.template.name'));
    }

    /** @return array<string, string> */
    public function newTemplatePayload(): array
    {
        if ($this->newTemplateChannel() === 'sms') {
            return ['message' => trim((string) $this->validated('new_step.template.message'))];
        }

        return [
            'subject' => trim((string) $this->validated('new_step.template.subject')),
            'body' => trim((string) $this->validated('new_step.template.body')),
        ];
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