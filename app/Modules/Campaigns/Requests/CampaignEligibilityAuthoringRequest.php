<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CampaignEligibilityAuthoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enrollment_mode' => [
                'required',
                'string',
                Rule::in(Campaign::ENROLLMENT_MODES),
            ],
            'reentry_policy' => [
                'required',
                'string',
                Rule::in(Campaign::REENTRY_POLICIES),
            ],
            'ineligible_behavior' => [
                'required',
                'string',
                Rule::in(Campaign::INELIGIBLE_BEHAVIORS),
            ],
            'eligibility_criteria' => [
                'nullable',
                'array',
            ],
            'eligibility_criteria.*' => [
                'array',
            ],
            'eligibility_criteria.*.*' => [
                'string',
                'max:255',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function eligibilityCriteria(): array
    {
        $criteria = $this->input('eligibility_criteria', []);

        return is_array($criteria) ? $criteria : [];
    }

    public function enrollmentMode(): string
    {
        return (string) $this->validated('enrollment_mode');
    }

    public function reentryPolicy(): string
    {
        return (string) $this->validated('reentry_policy');
    }

    public function ineligibleBehavior(): string
    {
        return (string) $this->validated('ineligible_behavior');
    }
}