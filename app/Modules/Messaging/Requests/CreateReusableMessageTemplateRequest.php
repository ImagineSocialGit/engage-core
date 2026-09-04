<?php

namespace App\Modules\Messaging\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateReusableMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'authoring_option' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:191'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'message' => ['nullable', 'string', 'max:1600'],
        ];
    }

    public function authoringOptionKey(): string
    {
        return trim((string) $this->validated('authoring_option'));
    }

    public function templateName(): string
    {
        return trim((string) $this->validated('name'));
    }

    /** @return array<string, string> */
    public function payloadForChannel(string $channel): array
    {
        return $channel === 'sms'
            ? ['message' => trim((string) $this->validated('message'))]
            : [
                'subject' => trim((string) $this->validated('subject')),
                'body' => trim((string) $this->validated('body')),
            ];
    }
}