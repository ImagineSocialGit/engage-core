<?php

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssuePublicBookingDestinationVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['bail', 'required', 'string', 'in:email,sms'],
            'destination' => ['bail', 'required', 'string', 'max:320'],
            'challenge_id' => ['prohibited'],
            'code' => ['prohibited'],
            'verification_code' => ['prohibited'],
            'verification_state' => ['prohibited'],
            'verification_proof' => ['prohibited'],
            'proof_token' => ['prohibited'],
            'verified' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'hold_id' => ['prohibited'],
            'bookable_service_id' => ['prohibited'],
            'scheduling_host_id' => ['prohibited'],
        ];
    }

    public function channel(): string
    {
        return trim((string) $this->validated('channel'));
    }

    public function destination(): string
    {
        return trim((string) $this->validated('destination'));
    }
}