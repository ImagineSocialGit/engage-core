<?php

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelAppointmentRequest extends FormRequest
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
            'cancellation_reason' => ['required', 'string', 'max:10000'],
            'override_cancellation_notice' => ['sometimes', 'boolean'],
            'status' => ['prohibited'],
            'canceled_at' => ['prohibited'],
            'attendee_status' => ['prohibited'],
            'source' => ['prohibited'],
            'actor_type' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'Enter a reason for canceling the appointment.',
            'override_cancellation_notice.boolean' => 'The cancellation-notice override is invalid.',
            '*.prohibited' => 'Appointment lifecycle state is server-owned.',
        ];
    }
}