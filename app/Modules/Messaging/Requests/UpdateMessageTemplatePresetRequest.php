<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

            $preset = $this->route('messageTemplatePreset');

            if (! $preset instanceof MessageTemplatePreset) {
                return;
            }

            $submittedPayload = $this->input('payload', []);
            $submittedPayload = is_array($submittedPayload)
                ? $this->cleanPayload($submittedPayload)
                : [];

            $payload = array_replace_recursive(
                is_array($preset->payload) ? $preset->payload : [],
                $submittedPayload,
            );

            $surface = $preset->catalogEntries()
                ->active()
                ->orderBy('item_order')
                ->orderBy('id')
                ->value('surface');

            $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
                payload: $payload,
                dispatchKeys: $preset->dispatchKeys(),
                channel: $preset->channel,
                purpose: $preset->purpose,
                scope: $preset->scope,
                surface: is_string($surface) && trim($surface) !== '' ? trim($surface) : null,
                path: 'payload',
            );

            foreach ($issues as $issue) {
                if (($issue['level'] ?? null) !== 'error') {
                    continue;
                }

                $path = is_string($issue['path'] ?? null) && trim($issue['path']) !== ''
                    ? $issue['path']
                    : 'payload';

                $message = is_string($issue['message'] ?? null) && trim($issue['message']) !== ''
                    ? $issue['message']
                    : 'The message template contains an invalid token.';

                $validator->errors()->add($path, $message);
            }
        });
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

        $ctas = $payload['ctas'] ?? null;

        if (is_array($ctas) && array_is_list($ctas)) {
            $cleanCtas = [];

            foreach ($ctas as $cta) {
                if (! is_array($cta)) {
                    continue;
                }

                $label = is_string($cta['label'] ?? null) ? trim($cta['label']) : '';
                $url = is_string($cta['url'] ?? null) ? trim($cta['url']) : '';

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

        return $preset instanceof MessageTemplatePreset
            && is_string($preset->payload_class)
            && $preset->payload_class === $expected;
    }
}
