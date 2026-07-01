<?php

namespace App\Modules\Messaging\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactPermissionInvitationConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'string', Rule::in(['email', 'sms'])],
            'phone' => [
                'nullable',
                'string',
                'max:40',
                Rule::requiredIf(fn (): bool => in_array('sms', $this->input('channels', []), true)),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function acceptedChannels(): array
    {
        $channels = $this->validated('channels');

        return is_array($channels)
            ? array_values(array_unique($channels))
            : [];
    }

    public function phone(): ?string
    {
        $phone = $this->validated('phone');

        return is_string($phone) && trim($phone) !== ''
            ? trim($phone)
            : null;
    }
}