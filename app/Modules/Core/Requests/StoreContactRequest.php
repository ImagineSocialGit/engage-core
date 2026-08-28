<?php

namespace App\Modules\Core\Requests;

use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_status_id' => ['nullable', 'integer', 'exists:contact_statuses,id'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'birthday' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'subsource' => ['nullable', 'string', 'max:255'],
            'existing_relationship_confirmed' => $this->messagingAvailable()
                ? ['required', 'accepted']
                : ['nullable', 'boolean'],
        ];
    }

    private function messagingAvailable(): bool
    {
        return in_array(
            'messaging',
            app(ModuleManager::class)->enabledKeysWithDependencies(),
            true,
        );
    }
}