<?php

namespace App\Modules\Scheduling\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class CreatePublicBookingHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'starts_at' => [
                'bail',
                'required',
                'string',
                'max:64',
                'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            ],
            'idempotency_key' => [
                'bail',
                'required',
                'string',
                'uuid',
                'max:36',
            ],
            'bookable_service_id' => ['prohibited'],
            'scheduling_host_id' => ['prohibited'],
            'ends_at' => ['prohibited'],
            'capacity' => ['prohibited'],
            'remaining_capacity' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'source_window_ids' => ['prohibited'],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            (string) $this->validated('starts_at'),
            'UTC',
        )->utc();
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->validated('idempotency_key'));
    }
}