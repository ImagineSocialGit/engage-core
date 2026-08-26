<?php

namespace App\Modules\FlowRoutes\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFlowRouteLeadInDelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $usesDelay = $this->input('start_timing') === 'delayed';
        $usesDuration = $usesDelay && $this->input('wait_mode', 'duration') === 'duration';
        $usesDateTime = $usesDelay && $this->input('wait_mode') === 'resume_at';

        return [
            'start_timing' => ['required', Rule::in(['immediate', 'delayed'])],
            'wait_mode' => [
                Rule::requiredIf($usesDelay),
                'nullable',
                Rule::in(['duration', 'resume_at']),
            ],
            'duration_value' => [
                Rule::requiredIf($usesDuration),
                'nullable',
                'integer',
                'min:0',
                'max:100000',
            ],
            'duration_unit' => [
                Rule::requiredIf($usesDuration),
                'nullable',
                Rule::in(['minutes', 'hours', 'days', 'business_days', 'weeks']),
            ],
            'resume_at' => [
                Rule::requiredIf($usesDateTime),
                'nullable',
                'date',
            ],
        ];
    }
}