<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Campaigns\Services\CampaignSendPatternService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCampaignSendPatternRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => [
                'required',
                'string',
                Rule::in([
                    CampaignSendPatternService::MODE_AS_DUE,
                    CampaignSendPatternService::MODE_SPREAD,
                ]),
            ],
            'daily_limit' => [
                'required_if:mode,'.CampaignSendPatternService::MODE_SPREAD,
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],
            'days_of_week' => [
                'required_if:mode,'.CampaignSendPatternService::MODE_SPREAD,
                'nullable',
                'array',
                'min:1',
            ],
            'days_of_week.*' => [
                'required',
                'integer',
                'between:1,7',
                'distinct',
            ],
            'window_start' => [
                'required_if:mode,'.CampaignSendPatternService::MODE_SPREAD,
                'nullable',
                'date_format:H:i',
            ],
            'window_end' => [
                'required_if:mode,'.CampaignSendPatternService::MODE_SPREAD,
                'nullable',
                'date_format:H:i',
                'after:window_start',
            ],
            'timezone' => [
                'required_if:mode,'.CampaignSendPatternService::MODE_SPREAD,
                'nullable',
                'timezone',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function sendPattern(): array
    {
        return [
            'mode' => (string) $this->validated('mode'),
            'daily_limit' => $this->validated('daily_limit'),
            'days_of_week' => $this->validated('days_of_week') ?? [],
            'window_start' => $this->validated('window_start'),
            'window_end' => $this->validated('window_end'),
            'timezone' => $this->validated('timezone'),
        ];
    }
}