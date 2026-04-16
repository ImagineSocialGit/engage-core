<?php

namespace App\Http\Requests\CRM;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
        ];
    }
}