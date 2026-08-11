<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWebinarSeriesRequest extends FormRequest
{
    private const EXISTING_SERIES_GUIDANCE = 'A webinar series with this title or public slug already exists. Use that series instead: choose its Zoom event type, then sync it. Occurrences of the other provider event type become historical automatically. Use occurrence replacement only when registrations must move to a replacement occurrence.';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        $providerEventType = $this->input('provider_event_type');

        $this->merge([
            'title' => is_string($title)
                ? trim($title)
                : $title,
            'provider_event_type' => is_string($providerEventType)
                ? strtolower(trim($providerEventType))
                : $providerEventType,
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255', 'unique:webinar_series,title'],
            'provider_event_type' => [
                'required',
                'string',
                Rule::in($this->supportedProviderEventTypes()),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('title')) {
                    return;
                }

                $title = $this->input('title');

                if (! is_string($title)) {
                    return;
                }

                $slug = Str::slug($title);

                if ($slug === '') {
                    return;
                }

                if (WebinarSeries::query()->where('slug', $slug)->exists()) {
                    $validator->errors()->add(
                        'title',
                        self::EXISTING_SERIES_GUIDANCE,
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'title.unique' => self::EXISTING_SERIES_GUIDANCE,
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

        if (! is_array($definitions)) {
            return [];
        }

        return collect(array_keys($definitions))
            ->map(fn (mixed $eventType): ?string =>
                WebinarProviderEventType::fromMixed($eventType)?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}