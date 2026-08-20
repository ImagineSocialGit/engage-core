<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Services\MessageTemplateCompositionSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMessageTemplateCompositionLayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'array', 'min:1'],
            'payload.subject' => ['nullable', 'string', 'max:255'],
            'payload.body' => ['nullable', 'string', 'max:10000'],
            'payload.message' => ['nullable', 'string', 'max:1600'],
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $layer = $this->route('messageTemplateCompositionLayer');

            if (! $layer instanceof MessageTemplateCompositionLayer) {
                return;
            }

            $payload = $this->cleanPayload($this->input('payload', []));

            try {
                app(MessageTemplateCompositionSchema::class)->validatePayload(
                    (string) $layer->channel,
                    $payload,
                );
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('payload', $exception->getMessage());
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function safePayload(): array
    {
        return $this->cleanPayload($this->validated('payload'));
    }

    /**
     * Preserve only fields already owned by this shared layer. The first UI slice
     * edits existing bounded shared content; it does not expand a layer's scope.
     *
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function cleanPayload(mixed $payload): array
    {
        $layer = $this->route('messageTemplateCompositionLayer');
        $current = $layer instanceof MessageTemplateCompositionLayer && is_array($layer->payload)
            ? $layer->payload
            : [];
        $payload = is_array($payload) ? $payload : [];
        $clean = [];

        foreach (array_keys($current) as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (in_array($key, ['subject', 'body', 'message', 'footer'], true)) {
                $clean[$key] = is_string($value) ? trim($value) : null;
                continue;
            }

            if (in_array($key, ['cta', 'secondary_link'], true) && is_array($value)) {
                $clean[$key] = [
                    'label' => is_string($value['label'] ?? null) ? trim($value['label']) : null,
                    'url' => is_string($value['url'] ?? null) ? trim($value['url']) : null,
                ];
                continue;
            }

            if ($key === 'ctas' && is_array($value) && array_is_list($value)) {
                $clean['ctas'] = array_values(array_map(
                    static fn (mixed $cta): array => [
                        'label' => is_array($cta) && is_string($cta['label'] ?? null) ? trim($cta['label']) : null,
                        'url' => is_array($cta) && is_string($cta['url'] ?? null) ? trim($cta['url']) : null,
                    ],
                    $value,
                ));
            }
        }

        return $clean;
    }
}