<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMessageTemplatePresetRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'payload' => ['required', 'array'],
            'payload.subject' => ['nullable', 'string', 'max:255', Rule::requiredIf($this->isEmailPayload())],
            'payload.body' => ['nullable', 'string', 'max:10000', Rule::requiredIf($this->isEmailPayload())],
            'payload.message' => ['nullable', 'string', 'max:1600', Rule::requiredIf($this->isSmsPayload())],
            'payload.footer' => ['nullable', 'string', 'max:2000'],
            'payload.cta' => ['nullable', 'array'],
            'payload.cta.label' => ['nullable', 'string', 'max:255'],
            'payload.cta.url' => ['nullable', 'string', 'max:1000'],
            'payload.secondary_link' => ['nullable', 'array'],
            'payload.secondary_link.label' => ['nullable', 'string', 'max:255'],
            'payload.secondary_link.url' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payload.subject.required' => 'Email templates need a subject.',
            'payload.body.required' => 'Email templates need body copy.',
            'payload.message.required' => 'SMS templates need message copy.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function safePayload(): array
    {
        $payload = $this->validated('payload');

        return $this->cleanPayload(is_array($payload) ? $payload : []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function cleanPayload(array $payload): array
    {
        $clean = [];

        foreach (['subject', 'body', 'message', 'footer'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_string($value) && trim($value) !== '') {
                $clean[$key] = trim($value);
            }
        }

        foreach (['cta', 'secondary_link'] as $key) {
            $link = $payload[$key] ?? null;

            if (! is_array($link)) {
                continue;
            }

            $label = is_string($link['label'] ?? null) ? trim($link['label']) : '';
            $url = is_string($link['url'] ?? null) ? trim($link['url']) : '';

            if ($label !== '' || $url !== '') {
                $clean[$key] = array_filter([
                    'label' => $label !== '' ? $label : null,
                    'url' => $url !== '' ? $url : null,
                ], static fn (mixed $value): bool => $value !== null);
            }
        }

        return $clean;
    }

    private function isEmailPayload(): bool
    {
        return $this->payloadClassIs(EmailPayload::class);
    }

    private function isSmsPayload(): bool
    {
        return $this->payloadClassIs(SmsPayload::class);
    }

    private function payloadClassIs(string $expected): bool
    {
        $preset = $this->route('messageTemplatePreset');

        return $preset && is_string($preset->payload_class ?? null)
            && $preset->payload_class === $expected;
    }
}
