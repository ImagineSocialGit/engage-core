<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Campaigns\Services\CampaignCreationGuide;
use App\Modules\Messaging\Requests\Concerns\InteractsWithMessageMediaAuthoring;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCampaignRequest extends FormRequest
{
    use InteractsWithMessageMediaAuthoring;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'creation_intent' => [
                'required',
                'string',
                Rule::in(app(CampaignCreationGuide::class)->keys()),
            ],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:4000'],
            'channel' => ['required', Rule::in(['email', 'sms'])],
            'subject' => ['nullable', 'required_if:channel,email', 'string', 'max:255'],
            'body' => ['nullable', 'required_if:channel,email', 'string', 'max:10000'],
            'message' => ['nullable', 'required_if:channel,sms', 'string', 'max:1600'],
        ], $this->messageMediaRules());
    }

    public function creationIntent(): string
    {
        return trim((string) $this->validated('creation_intent'));
    }

    public function campaignName(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function campaignDescription(): ?string
    {
        $description = $this->validated('description');

        if (! is_string($description) || trim($description) === '') {
            return null;
        }

        return trim($description);
    }

    public function channel(): string
    {
        return strtolower(trim((string) $this->validated('channel')));
    }

    /** @return array<string, mixed> */
    public function payloadForChannel(): array
    {
        return $this->channel() === 'sms'
            ? [
                'message' => trim((string) $this->validated('message')),
            ]
            : [
                'subject' => trim((string) $this->validated('subject')),
                'body' => trim((string) $this->validated('body')),
            ];
    }
}