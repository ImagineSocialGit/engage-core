<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Requests\Concerns\InteractsWithMessageMediaAuthoring;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebinarSeriesMessageTemplateRequest extends FormRequest
{
    use InteractsWithMessageMediaAuthoring;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            '_editing_message_id' => ['nullable', 'string', 'max:191'],
            'webinar_id' => ['nullable', 'integer', 'exists:webinars,id'],
            'return_surface' => ['nullable', Rule::in(['series_detail'])],
            'payload' => ['required', 'array'],
            'payload.subject' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf($this->variant()?->channel === 'email'),
            ],
            'payload.body' => [
                'nullable',
                'string',
                'max:10000',
                Rule::requiredIf($this->variant()?->channel === 'email'),
            ],
            'payload.message' => [
                'nullable',
                'string',
                'max:1600',
                Rule::requiredIf($this->variant()?->channel === 'sms'),
            ],
            'payload.footer' => ['nullable', 'string', 'max:2000'],
            'payload.cta' => ['nullable', 'array'],
            'payload.cta.label' => ['nullable', 'string', 'max:255'],
            'payload.cta.url' => ['nullable', 'string', 'max:1000'],
            'payload.ctas' => ['nullable', 'array'],
            'payload.ctas.*' => ['nullable', 'array'],
            'payload.ctas.*.label' => ['nullable', 'string', 'max:255'],
            'payload.ctas.*.url' => ['nullable', 'string', 'max:1000'],
            'payload.secondary_link' => ['nullable', 'array'],
            'payload.secondary_link.label' => ['nullable', 'string', 'max:255'],
            'payload.secondary_link.url' => ['nullable', 'string', 'max:1000'],
            ...$this->messageMediaRules('payload'),
        ];
    }

    /** @return array<string, mixed> */
    public function safePayload(): array
    {
        $payload = $this->validated('payload');

        if (! is_array($payload)) {
            return [];
        }

        $clean = [];

        foreach (['subject', 'body', 'message', 'footer'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_string($value)) {
                $clean[$key] = trim($value);
            }
        }

        foreach (['cta', 'secondary_link'] as $key) {
            $link = $payload[$key] ?? null;

            if (! is_array($link)) {
                continue;
            }

            $label = is_string($link['label'] ?? null)
                ? trim($link['label'])
                : '';
            $url = is_string($link['url'] ?? null)
                ? trim($link['url'])
                : '';

            if ($label !== '' || $url !== '') {
                $clean[$key] = array_filter([
                    'label' => $label !== '' ? $label : null,
                    'url' => $url !== '' ? $url : null,
                ], static fn (mixed $value): bool => $value !== null);
            }
        }

        $ctas = $payload['ctas'] ?? null;

        if (is_array($ctas) && array_is_list($ctas)) {
            $cleanCtas = [];

            foreach ($ctas as $cta) {
                if (! is_array($cta)) {
                    continue;
                }

                $label = is_string($cta['label'] ?? null)
                    ? trim($cta['label'])
                    : '';
                $url = is_string($cta['url'] ?? null)
                    ? trim($cta['url'])
                    : '';

                if ($label === '' && $url === '') {
                    continue;
                }

                $cleanCtas[] = array_filter([
                    'label' => $label !== '' ? $label : null,
                    'url' => $url !== '' ? $url : null,
                ], static fn (mixed $value): bool => $value !== null);
            }

            if ($cleanCtas !== []) {
                $clean['ctas'] = $cleanCtas;
            }
        }

        return $clean;
    }

    public function successRedirectUrl(WebinarSeries $series): string
    {
        return $this->webinarRedirectUrl($series)
            ?? $this->seriesDetailRedirectUrl($series)
            ?? route('crm.webinar-series.message-chains.show', $series);
    }

    protected function getRedirectUrl(): string
    {
        $series = $this->route('series');

        if ($series instanceof WebinarSeries) {
            return $this->successRedirectUrl($series);
        }

        return parent::getRedirectUrl();
    }

    private function seriesDetailRedirectUrl(WebinarSeries $series): ?string
    {
        if ($this->input('return_surface') !== 'series_detail') {
            return null;
        }

        return route('crm.webinar-series.show', [
            'series' => $series,
            'messages' => 1,
        ]).'#message-plan';
    }

    private function webinarRedirectUrl(WebinarSeries $series): ?string
    {
        $webinarId = (int) $this->input('webinar_id', 0);

        if ($webinarId <= 0) {
            return null;
        }

        $belongsToSeries = Webinar::query()
            ->whereKey($webinarId)
            ->where('webinar_series_id', $series->getKey())
            ->exists();

        if (! $belongsToSeries) {
            return null;
        }

        return route('crm.webinar-series.index', [
            'messages' => $webinarId,
        ]);
    }

    private function variant(): ?MessageChainStepVariant
    {
        $variant = $this->route('variant');

        return $variant instanceof MessageChainStepVariant
            ? $variant
            : null;
    }
}