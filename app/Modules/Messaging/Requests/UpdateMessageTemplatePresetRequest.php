<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Messaging\Services\MessageTokenFallbackResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMessageTemplatePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
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
            'payload.token_fallbacks_present' => ['nullable', 'boolean'],
            'payload.token_fallbacks' => ['nullable', 'array', 'max:50'],
            'payload.token_fallbacks.*' => ['required', 'array'],
            'payload.token_fallbacks.*.token' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-zA-Z_][a-zA-Z0-9_.:-]*$/',
            ],
            'payload.token_fallbacks.*.missing_behavior' => [
                'required',
                'string',
                Rule::in(MessageTokenFallbackResolver::BEHAVIORS),
            ],
            'payload.token_fallbacks.*.fallback' => ['nullable', 'string', 'max:2000'],
            'payload.token_fallbacks.*.segment' => ['nullable', 'string', 'max:5000'],
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

            $submittedPayload = $this->safePayloadFromInput();
            $payload = array_replace_recursive(
                is_array($preset->payload) ? $preset->payload : [],
                $submittedPayload,
            );

            if (array_key_exists('token_fallbacks', $submittedPayload)) {
                $payload['token_fallbacks'] = $submittedPayload['token_fallbacks'];
            }

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

                $validator->errors()->add(
                    is_string($issue['path'] ?? null) && trim($issue['path']) !== '' ? $issue['path'] : 'payload',
                    is_string($issue['message'] ?? null) && trim($issue['message']) !== ''
                        ? $issue['message']
                        : 'The message template contains an invalid dynamic field.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payload.subject.required' => 'Email templates need a subject.',
            'payload.body.required' => 'Email templates need body copy.',
            'payload.message.required' => 'SMS templates need message copy.',
        ];
    }

    /** @return array<string, mixed> */
    public function safePayload(): array
    {
        return $this->cleanPayload($this->validated('payload'));
    }

    /** @return array<string, mixed> */
    private function safePayloadFromInput(): array
    {
        return $this->cleanPayload($this->input('payload', []));
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function cleanPayload(mixed $payload): array
    {
        $payload = is_array($payload) ? $payload : [];
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

        if ($this->hasTokenFallbackSubmission($payload)) {
            $clean['token_fallbacks'] = $this->cleanTokenFallbacks(
                payload: $payload,
                contentPayload: $clean,
            );
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasTokenFallbackSubmission(array $payload): bool
    {
        if (array_key_exists('token_fallbacks', $payload)) {
            return true;
        }

        return in_array(
            $payload['token_fallbacks_present'] ?? null,
            [true, 1, '1', 'true', 'on'],
            true,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $contentPayload
     * @return array<int, array<string, string>>
     */
    private function cleanTokenFallbacks(
        array $payload,
        array $contentPayload,
    ): array {
        $raw = $payload['token_fallbacks'] ?? [];

        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }

        $usedTokens = app(MessageTemplateTokenValidator::class)
            ->resolvableTokensFromPayload($contentPayload);
        $clean = [];
        $seen = [];

        foreach ($raw as $policy) {
            if (! is_array($policy)) {
                continue;
            }

            $token = is_string($policy['token'] ?? null)
                ? trim($policy['token'])
                : '';
            $behavior = is_string($policy['missing_behavior'] ?? null)
                ? trim($policy['missing_behavior'])
                : '';

            if ($token === ''
                || isset($seen[$token])
                || ! in_array($token, $usedTokens, true)
                || ! in_array($behavior, MessageTokenFallbackResolver::BEHAVIORS, true)
            ) {
                continue;
            }

            $normalized = [
                'token' => $token,
                'missing_behavior' => $behavior,
            ];

            if ($behavior === MessageTokenFallbackResolver::BEHAVIOR_FALLBACK_VALUE) {
                $normalized['fallback'] = is_string($policy['fallback'] ?? null)
                    ? trim($policy['fallback'])
                    : '';
            }

            if ($behavior === MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT) {
                $normalized['segment'] = is_string($policy['segment'] ?? null)
                    ? $policy['segment']
                    : '';
                $normalized['fallback'] = is_string($policy['fallback'] ?? null)
                    ? $policy['fallback']
                    : '';
            }

            $clean[] = $normalized;
            $seen[$token] = true;
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