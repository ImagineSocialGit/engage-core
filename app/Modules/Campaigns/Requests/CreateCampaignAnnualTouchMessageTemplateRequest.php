<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCampaignAnnualTouchMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(['email', 'sms'])],
            'name' => ['required', 'string', 'max:191'],
            'subject' => [
                Rule::requiredIf(fn (): bool => $this->channel() === 'email'),
                'nullable',
                'string',
                'max:255',
            ],
            'body' => [
                Rule::requiredIf(fn (): bool => $this->channel() === 'email'),
                'nullable',
                'string',
            ],
            'message' => [
                Rule::requiredIf(fn (): bool => $this->channel() === 'sms'),
                'nullable',
                'string',
                'max:1600',
            ],
        ];
    }

    public function channel(): string
    {
        return $this->input('channel') === 'sms' ? 'sms' : 'email';
    }

    public function templateName(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function payloadClass(): string
    {
        return $this->channel() === 'sms' ? SmsPayload::class : EmailPayload::class;
    }

    /** @return array<string, string> */
    public function payload(): array
    {
        if ($this->channel() === 'sms') {
            return [
                'message' => trim((string) $this->validated('message')),
            ];
        }

        return [
            'subject' => trim((string) $this->validated('subject')),
            'body' => trim((string) $this->validated('body')),
        ];
    }
}