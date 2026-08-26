<?php

namespace App\Modules\InboundMessaging\Requests;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveInboundEmailRouteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'local_part' => $this->normalized($this->input('local_part')),
            'label' => $this->trimmed($this->input('label')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $route = $this->route('inboundEmailRoute');
        $routeId = $route instanceof InboundEmailRoute
            ? $route->getKey()
            : null;

        return [
            'local_part' => [
                'required',
                'string',
                'max:190',
                'regex:/^[a-z0-9][a-z0-9._+\-]*$/',
                Rule::unique('inbound_email_routes', 'local_part')
                    ->ignore($routeId),
            ],
            'label' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $localPart = $this->input('local_part');

            if (is_string($localPart)
                && str_starts_with(
                    mb_strtolower(trim($localPart)),
                    'reply+',
                )
            ) {
                $validator->errors()->add(
                    'local_part',
                    'That address prefix is reserved for direct replies to Engage messages.',
                );
            }
        });
    }

    /**
     * @return array{local_part: string, label: string}
     */
    public function definition(): array
    {
        $validated = $this->validated();

        return [
            'local_part' => (string) $validated['local_part'],
            'label' => (string) $validated['label'],
        ];
    }

    private function normalized(mixed $value): mixed
    {
        return is_string($value)
            ? mb_strtolower(trim($value))
            : $value;
    }

    private function trimmed(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}