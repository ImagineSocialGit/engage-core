<?php

namespace App\Modules\Broadcasts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveBroadcastMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
        ];
    }

    public function templateName(): string
    {
        return trim((string) $this->validated('name'));
    }
}