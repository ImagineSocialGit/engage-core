<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWebinarSeriesVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'public_slug', 'timezone', 'provider_match_title', 'provider_event_type', 'status'] as $field) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            $this->merge([
                $field => $field === 'public_slug'
                    ? Str::slug($value)
                    : (in_array($field, ['provider_event_type', 'status'], true) ? strtolower($value) : $value),
            ]);
        }
    }

    public function rules(): array
    {
        $variant = $this->route('variant');
        $variantId = $variant instanceof WebinarSeriesVariant
            ? $variant->getKey()
            : $variant;

        return [
            'name' => ['required', 'string', 'max:100'],
            'public_slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('webinar_series_variants', 'public_slug')->ignore($variantId),
            ],
            'timezone' => ['required', 'string', 'timezone'],
            'provider_event_type' => [
                'required',
                'string',
                Rule::in($this->supportedProviderEventTypes()),
            ],
            'provider_match_title' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $variant = $this->route('variant');
                $slug = $this->input('public_slug');

                if (! $variant instanceof WebinarSeriesVariant
                    || ! is_string($slug)
                    || trim($slug) === ''
                ) {
                    return;
                }

                $slug = trim($slug);
                $seriesCollision = WebinarSeries::query()
                    ->where('slug', $slug)
                    ->where('id', '!=', $variant->webinar_series_id)
                    ->exists();

                if ($seriesCollision) {
                    $validator->errors()->add(
                        'public_slug',
                        'That public webinar URL is already in use.',
                    );
                }

                if ($variant->is_default
                    && $variant->webinarSeries
                    && $slug !== $variant->webinarSeries->slug
                ) {
                    $validator->errors()->add(
                        'public_slug',
                        'The primary variant keeps the webinar series public URL so existing links and ads remain valid.',
                    );
                }

                if ($variant->is_default
                    && $variant->webinarSeries?->status === 'active'
                    && $this->input('status') !== 'active'
                ) {
                    $validator->errors()->add(
                        'status',
                        'Archive the webinar series instead of disabling its primary market.',
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
}