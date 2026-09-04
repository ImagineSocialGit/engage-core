<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Requests\Concerns\InteractsWithMessageMediaAuthoring;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendContactDirectMessageRequest extends FormRequest
{
    use InteractsWithMessageMediaAuthoring;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'direct_message' => ['required', 'array'],
            'direct_message.request_key' => ['required', 'uuid'],
            'direct_message.channel' => ['required', 'string', Rule::in(MessageChannel::values())],
            'direct_message.purpose' => ['required', 'string', Rule::in(MessagePurpose::values())],
            'direct_message.template_preset_id' => ['nullable', 'integer', 'min:1'],
            'direct_message.subject' => [
                Rule::requiredIf(fn (): bool => $this->channel() === MessageChannel::Email->value),
                'nullable',
                'string',
                'max:255',
            ],
            'direct_message.body' => [
                Rule::requiredIf(fn (): bool => $this->channel() === MessageChannel::Email->value),
                'nullable',
                'string',
                'max:10000',
            ],
            'direct_message.message' => [
                Rule::requiredIf(fn (): bool => $this->channel() === MessageChannel::Sms->value),
                'nullable',
                'string',
                'max:1600',
            ],
        ], $this->messageMediaRules('direct_message'));
    }

    public function requestKey(): string
    {
        return (string) $this->validated('direct_message.request_key');
    }

    public function channel(): string
    {
        return (string) $this->input('direct_message.channel', '');
    }

    public function purpose(): string
    {
        return (string) $this->input('direct_message.purpose', '');
    }

    public function templatePresetId(): ?int
    {
        $value = $this->validated('direct_message.template_preset_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function subject(): ?string
    {
        return $this->nullableTrimmed('direct_message.subject');
    }

    public function body(): ?string
    {
        return $this->nullableTrimmed('direct_message.body');
    }

    public function message(): ?string
    {
        return $this->nullableTrimmed('direct_message.message');
    }

    private function nullableTrimmed(string $key): ?string
    {
        $value = $this->validated($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}