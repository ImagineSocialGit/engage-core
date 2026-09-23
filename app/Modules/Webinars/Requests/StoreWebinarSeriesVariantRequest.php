<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWebinarSeriesVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'public_slug', 'timezone', 'provider_match_title', 'provider_event_type'] as $field) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            $this->merge([
                $field => $field === 'public_slug'
                    ? Str::slug($value)
                    : ($field === 'provider_event_type' ? strtolower($value) : $value),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'public_slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('webinar_series_variants', 'public_slug'),
            ],
            'timezone' => ['required', 'string', 'timezone'],
            'provider_event_type' => [
                'required',
                'string',
                Rule::in($this->supportedProviderEventTypes()),
            ],
            'provider_match_title' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $slug = $this->validatedString('public_slug');

                if ($slug !== null
                    && WebinarSeries::query()->where('slug', $slug)->exists()
                ) {
                    $validator->errors()->add(
                        'public_slug',
                        'That public webinar URL is already in use.',
                    );
                }
            },
        ];
    }

    /** @return array<int, string> */
    private function supportedProviderEventTypes(): array
    {
        $provider = config('webinars.provider', 'zoom');
        $provider = is_string($provider) && trim($provider) !== ''
            ? strtolower(trim($provider))
            : 'zoom';
        $definitions = config("webinars.providers.{$provider}.event_types", []);

        return is_array($definitions)
            ? collect(array_keys($definitions))
                ->map(fn (mixed $type): ?string => WebinarProviderEventType::fromMixed($type)?->value)
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];
    }

    private function validatedString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}