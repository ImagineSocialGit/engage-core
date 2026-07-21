<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebinarSeriesProviderEventTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_event_type' => [
                'required',
                'string',
                Rule::in($this->supportedProviderEventTypes()),
            ],
        ];
    }

    /** @return array<int, string> */
    private function supportedProviderEventTypes(): array
    {
        $series = $this->route('series');
        $provider = $series instanceof WebinarSeries
            ? $series->providerKey()
            : config('webinars.provider', 'zoom');
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