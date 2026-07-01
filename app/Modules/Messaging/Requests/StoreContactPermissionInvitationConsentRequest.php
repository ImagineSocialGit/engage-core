<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Services\MessageChannelAvailability;
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
        $availableChannels = $this->availableChannels();

        return [
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'string', Rule::in($availableChannels)],
            'phone' => [
                'nullable',
                'string',
                'max:40',
                Rule::requiredIf(fn (): bool => in_array('sms', $this->input('channels', []), true)
                    && in_array('sms', $availableChannels, true)),
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
            ? app(MessageChannelAvailability::class)->normalizeVisibleChannelsForSurface(
                channels: $channels,
                surface: 'permission_invitations',
                purpose: 'marketing',
                scope: 'broadcast',
            )
            : [];
    }

    public function phone(): ?string
    {
        $phone = $this->validated('phone');

        return is_string($phone) && trim($phone) !== ''
            ? trim($phone)
            : null;
    }

    /**
     * @return array<int, string>
     */
    private function availableChannels(): array
    {
        return app(MessageChannelAvailability::class)->visibleChannelsForSurface(
            surface: 'permission_invitations',
            purpose: 'marketing',
            scope: 'broadcast',
        ) ?: ['email'];
    }
}