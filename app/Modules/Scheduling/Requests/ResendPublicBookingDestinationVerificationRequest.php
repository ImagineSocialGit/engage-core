<?php

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResendPublicBookingDestinationVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['prohibited'],
            'code' => ['prohibited'],
            'verification_code' => ['prohibited'],
            'verification_state' => ['prohibited'],
            'channel' => ['prohibited'],
            'destination' => ['prohibited'],
            'verification_proof' => ['prohibited'],
            'proof_token' => ['prohibited'],
            'verified' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'hold_id' => ['prohibited'],
        ];
    }
}