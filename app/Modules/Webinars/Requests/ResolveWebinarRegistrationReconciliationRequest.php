<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Webinars\Actions\ResolveWebinarRegistrationReconciliationAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveWebinarRegistrationReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    ResolveWebinarRegistrationReconciliationAction::DECISION_PROVIDER_EXISTS,
                    ResolveWebinarRegistrationReconciliationAction::DECISION_PROVIDER_ABSENT,
                ]),
            ],
            'provider_registrant_id' => [
                'nullable',
                'string',
                'max:255',
                'required_if:decision,'.ResolveWebinarRegistrationReconciliationAction::DECISION_PROVIDER_EXISTS,
            ],
            'provider_join_url' => [
                'nullable',
                'url:http,https',
                'max:2048',
                'required_if:decision,'.ResolveWebinarRegistrationReconciliationAction::DECISION_PROVIDER_EXISTS,
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}