<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCampaignExecutionStrategyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'execution_strategy' => [
                'required',
                'string',
                Rule::in(Campaign::EXECUTION_STRATEGIES),
            ],
            'allocation_settings' => ['nullable', 'array'],
            'allocation_settings.run_every_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'allocation_settings.allocation_size_per_message' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'allocation_settings.recipient_cooldown_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    public function executionStrategy(): string
    {
        return strtolower(trim((string) $this->validated('execution_strategy')));
    }

    /** @return array<string, mixed>|null */
    public function allocationSettings(): ?array
    {
        if ($this->executionStrategy() !== Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION) {
            return null;
        }

        $settings = $this->validated('allocation_settings', []);

        return is_array($settings) ? $settings : [];
    }
}