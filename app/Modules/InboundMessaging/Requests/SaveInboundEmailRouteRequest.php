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
            'key' => $this->normalized($this->input('key')),
            'local_part' => $this->normalized($this->input('local_part')),
            'label' => $this->trimmed($this->input('label')),
            'source' => $this->normalized($this->input('source')),
            'context_key' => $this->nullableNormalized(
                $this->input('context_key'),
            ),
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
            'key' => [
                'required',
                'string',
                'max:96',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                Rule::unique('inbound_email_routes', 'key')->ignore($routeId),
            ],
            'local_part' => [
                'required',
                'string',
                'max:190',
                'regex:/^[a-z0-9][a-z0-9._+\-]*$/',
                Rule::unique('inbound_email_routes', 'local_part')->ignore($routeId),
            ],
            'label' => [
                'required',
                'string',
                'max:255',
            ],
            'source' => [
                'required',
                'string',
                'max:96',
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
            ],
            'context_key' => [
                'nullable',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $localPart = $this->input('local_part');

            if (is_string($localPart)
                && str_starts_with(mb_strtolower(trim($localPart)), 'reply+')
            ) {
                $validator->errors()->add(
                    'local_part',
                    'The reply+ namespace is reserved for signed Engage replies.',
                );
            }

            $route = $this->route('inboundEmailRoute');
            $submittedKey = $this->input('key');

            if ($route instanceof InboundEmailRoute
                && is_string($submittedKey)
                && ! hash_equals((string) $route->key, $submittedKey)
            ) {
                $validator->errors()->add(
                    'key',
                    'Inbound route keys cannot be changed after creation.',
                );
            }
        });
    }

    /**
     * @return array{
     *     key: string,
     *     local_part: string,
     *     label: string,
     *     source: string,
     *     context_key: ?string
     * }
     */
    public function definition(): array
    {
        $validated = $this->validated();

        return [
            'key' => (string) $validated['key'],
            'local_part' => (string) $validated['local_part'],
            'label' => (string) $validated['label'],
            'source' => (string) $validated['source'],
            'context_key' => isset($validated['context_key'])
                && is_string($validated['context_key'])
                && $validated['context_key'] !== ''
                    ? $validated['context_key']
                    : null,
        ];
    }

    private function normalized(mixed $value): mixed
    {
        return is_string($value)
            ? mb_strtolower(trim($value))
            : $value;
    }

    private function nullableNormalized(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = mb_strtolower(trim($value));

        return $value !== '' ? $value : null;
    }

    private function trimmed(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}