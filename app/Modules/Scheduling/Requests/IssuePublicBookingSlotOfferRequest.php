<?php

namespace App\Modules\Scheduling\Requests;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Services\SchedulingLocalDateTimeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class IssuePublicBookingSlotOfferRequest extends FormRequest
{
    private ?BookableService $resolvedService = null;
    private bool $serviceResolved = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $range = fn (): bool => $this->usesRangeDuration();
        $fixed = fn (): bool => ! $this->usesRangeDuration();

        return [
            'starts_at' => [
                'bail',
                Rule::requiredIf($fixed),
                Rule::prohibitedIf($range),
                'string',
                'max:64',
                'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            ],
            'range_starts_at' => [
                'bail',
                Rule::requiredIf($range),
                Rule::prohibitedIf($fixed),
                'string',
                'max:16',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
            ],
            'range_ends_at' => [
                'bail',
                Rule::requiredIf($range),
                Rule::prohibitedIf($fixed),
                'string',
                'max:16',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
            ],
            'idempotency_key' => ['prohibited'],
            'bookable_service_id' => ['prohibited'],
            'scheduling_host_id' => ['prohibited'],
            'ends_at' => ['prohibited'],
            'capacity' => ['prohibited'],
            'remaining_capacity' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'hold_id' => ['prohibited'],
            'source_window_ids' => ['prohibited'],
            'location_type' => ['prohibited'],
            'location_details' => ['prohibited'],
            'address_line_1' => ['prohibited'],
            'address_line_2' => ['prohibited'],
            'city' => ['prohibited'],
            'region' => ['prohibited'],
            'postal_code' => ['prohibited'],
            'country' => ['prohibited'],
            'formatted_address' => ['prohibited'],
            'latitude' => ['prohibited'],
            'longitude' => ['prohibited'],
            'timezone' => ['prohibited'],
            'precision' => ['prohibited'],
            'confidence' => ['prohibited'],
            'provider' => ['prohibited'],
            'verification_state' => ['prohibited'],
            'verified' => ['prohibited'],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        if ($this->usesRangeDuration()) {
            return $this->localDateTimes()->resolve(
                $this->validated('range_starts_at'),
                $this->serviceTimezone(),
                'check-in time',
            );
        }

        return CarbonImmutable::parse(
            (string) $this->validated('starts_at'),
            'UTC',
        )->utc();
    }

    public function endsAt(): ?CarbonImmutable
    {
        if (! $this->usesRangeDuration()) {
            return null;
        }

        return $this->localDateTimes()->resolve(
            $this->validated('range_ends_at'),
            $this->serviceTimezone(),
            'check-out time',
        );
    }

    private function usesRangeDuration(): bool
    {
        return $this->service()?->usesRangeDuration() ?? false;
    }

    private function service(): ?BookableService
    {
        if ($this->serviceResolved) {
            return $this->resolvedService;
        }

        $this->serviceResolved = true;
        $serviceKey = $this->route('serviceKey');

        if (! is_string($serviceKey) || trim($serviceKey) === '') {
            return null;
        }

        return $this->resolvedService = BookableService::query()
            ->where('key', trim($serviceKey))
            ->where('status', BookableService::STATUS_ACTIVE)
            ->where('is_public', true)
            ->whereNull('deleted_at')
            ->first();
    }

    private function serviceTimezone(): string
    {
        $timezone = $this->service()?->timezone;

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('The selected service timezone is invalid.');
        }

        return $timezone;
    }

    private function localDateTimes(): SchedulingLocalDateTimeResolver
    {
        return app(SchedulingLocalDateTimeResolver::class);
    }
}