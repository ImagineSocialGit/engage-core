<?php

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompletePublicBookingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');
        $phone = $this->input('phone');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
            'phone' => is_string($phone) && trim($phone) !== ''
                ? trim($phone)
                : null,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['bail', 'nullable', 'string', 'max:255'],
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

    public function attendeeName(): string
    {
        return trim((string) $this->validated('name'));
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
}