<?php

namespace App\Modules\FlowRoutes\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlowRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contact_status_id' => [
                'required',
                'integer',
                Rule::exists('contact_statuses', 'id')->where(
                    fn ($query) => $query->where('is_active', true),
                ),
            ],
        ];
    }

    public function routeName(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function routeDescription(): ?string
    {
        $description = trim((string) ($this->validated('description') ?? ''));

        return $description !== '' ? $description : null;
    }

    public function contactStatusId(): int
    {
        return (int) $this->validated('contact_status_id');
    }
}