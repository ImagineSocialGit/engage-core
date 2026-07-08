<?php

namespace App\Modules\Webinars\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebinarSeriesScheduleProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'webinar_schedule_profile_id' => [
                'nullable',
                'integer',
                Rule::exists('webinar_schedule_profiles', 'id')->where('is_active', true)->where('status', 'active'),
            ],
        ];
    }
}
