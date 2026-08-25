<?php

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPublicBookingDestinationVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $digits = min(8, max(4, (int) config(
            'scheduling.public.destination_verification.code_digits',
            6,
        )));

        return [
            'code' => [
                'bail',
                'required',
                'string',
                'size:'.$digits,
                'regex:/^\\d+$/D',
            ],
            'challenge_id' => ['prohibited'],
            'channel' => ['prohibited'],
            'destination' => ['prohibited'],
            'verification_state' => ['prohibited'],
            'verification_proof' => ['prohibited'],
            'proof_token' => ['prohibited'],
            'verified' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'hold_id' => ['prohibited'],
        ];
    }

    public function code(): string
    {
        return trim((string) $this->validated('code'));
    }
}