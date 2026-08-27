<?php

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompletePublicBookingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => $this->trimmed('first_name'),
            'last_name' => $this->trimmed('last_name'),
            'email' => is_string($this->input('email'))
                ? strtolower(trim($this->input('email')))
                : $this->input('email'),
            'phone' => $this->normalizedPhone($this->input('phone')),
            'public_submission_attempt_id' => $this->nullableTrimmed(
                'public_submission_attempt_id',
            ),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['bail', 'required', 'string', 'max:120'],
            'last_name' => ['bail', 'required', 'string', 'max:120'],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['bail', 'nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/D'],
            'public_submission_attempt_id' => ['bail', 'nullable', 'uuid'],
            'name' => ['prohibited'],
            'contact_id' => ['prohibited'],
            'appointment_id' => ['prohibited'],
            'bookable_service_id' => ['prohibited'],
            'scheduling_host_id' => ['prohibited'],
            'primary_attendee_type' => ['prohibited'],
            'primary_attendee_id' => ['prohibited'],
            'starts_at' => ['prohibited'],
            'ends_at' => ['prohibited'],
            'status' => ['prohibited'],
            'source' => ['prohibited'],
            'requires_confirmation' => ['prohibited'],
            'confirmed_at' => ['prohibited'],
            'capacity' => ['prohibited'],
            'offer_id' => ['prohibited'],
        ];
    }

    public function attendeeFirstName(): string
    {
        return trim((string) $this->validated('first_name'));
    }

    public function attendeeLastName(): string
    {
        return trim((string) $this->validated('last_name'));
    }

    public function attendeeName(): string
    {
        return trim($this->attendeeFirstName().' '.$this->attendeeLastName());
    }

    public function attendeeEmail(): string
    {
        return strtolower(trim((string) $this->validated('email')));
    }

    public function attendeePhone(): ?string
    {
        $phone = $this->validated('phone');

        return is_string($phone) && trim($phone) !== ''
            ? trim($phone)
            : null;
    }

    public function publicSubmissionAttemptId(): ?string
    {
        $attemptId = $this->validated('public_submission_attempt_id');

        return is_string($attemptId) && trim($attemptId) !== ''
            ? strtolower(trim($attemptId))
            : null;
    }

    private function trimmed(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }

    private function nullableTrimmed(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function normalizedPhone(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            return $value;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) >= 7 && strlen($digits) <= 15) {
            return '+'.$digits;
        }

        return $value;
    }
}