<?php

namespace App\Modules\InboundMessaging\Requests;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveInboundReplyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $profile = $this->route('inboundReplyProfile');
        $uniqueKey = Rule::unique('inbound_reply_profiles', 'key')
            ->whereNull('deleted_at');

        if ($profile instanceof InboundReplyProfile) {
            $uniqueKey->ignore($profile->getKey());
        }

        return [
            'key' => [
                'required',
                'string',
                'max:96',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                $uniqueKey,
            ],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'intents' => ['required', 'array', 'min:1', 'max:20'],
            'intents.*.key' => [
                'required',
                'string',
                'max:96',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                'distinct',
            ],
            'intents.*.label' => ['required', 'string', 'max:255'],
            'intents.*.description' => ['nullable', 'string', 'max:2000'],
            'intents.*.is_active' => ['nullable', 'boolean'],
            'intents.*.exact' => ['nullable', 'string', 'max:13000'],
            'intents.*.keywords' => ['nullable', 'string', 'max:13000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('intents', []) as $index => $intent) {
                if (! is_array($intent)) {
                    continue;
                }

                if ($this->lines($intent['exact'] ?? null) === []
                    && $this->lines($intent['keywords'] ?? null) === []
                ) {
                    $validator->errors()->add(
                        "intents.{$index}.exact",
                        'Add at least one exact reply or keyword phrase.',
                    );
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => trim((string) $this->validated('key')),
            'label' => trim((string) $this->validated('label')),
            'description' => $this->nullableString($this->validated('description')),
            'intents' => collect($this->validated('intents'))
                ->mapWithKeys(function (array $intent, int $index): array {
                    $key = trim((string) $intent['key']);

                    return [$key => [
                        'key' => $key,
                        'label' => trim((string) $intent['label']),
                        'description' => $this->nullableString($intent['description'] ?? null),
                        'is_active' => (bool) ($intent['is_active'] ?? false),
                        'sort_order' => ($index + 1) * 10,
                        'exact' => $this->lines($intent['exact'] ?? null),
                        'keywords' => $this->lines($intent['keywords'] ?? null),
                    ]];
                })
                ->all(),
        ];
    }

    /** @return array<int, string> */
    private function lines(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $value) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->unique(fn (string $line): string => Str::lower($line))
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}