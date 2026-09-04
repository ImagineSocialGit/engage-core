<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Requests\Concerns\InteractsWithMessageMediaAuthoring;
use Illuminate\Foundation\Http\FormRequest;

final class CreateReusableMessageTemplateRequest extends FormRequest
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
            'authoring_option' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:191'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'message' => ['nullable', 'string', 'max:1600'],
        ], $this->messageMediaRules());
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