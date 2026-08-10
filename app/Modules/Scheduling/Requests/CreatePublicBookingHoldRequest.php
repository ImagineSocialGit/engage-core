<?php

namespace App\Modules\Scheduling\Requests;

use App\Modules\Scheduling\Models\BookableService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePublicBookingHoldRequest extends FormRequest
{
    private ?bool $customerSite = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerSite = fn (): bool => $this->requiresCustomerSiteAddress();
        $notCustomerSite = fn (): bool => ! $this->requiresCustomerSiteAddress();

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
            'address_line_1' => [
                Rule::requiredIf($customerSite),
                Rule::prohibitedIf($notCustomerSite),
                'string',
                'max:255',
            ],
            'address_line_2' => [
                'nullable',
                Rule::prohibitedIf($notCustomerSite),
                'string',
                'max:255',
            ],
            'city' => [
                Rule::requiredIf($customerSite),
                Rule::prohibitedIf($notCustomerSite),
                'string',
                'max:255',
            ],
            'region' => [
                Rule::requiredIf($customerSite),
                Rule::prohibitedIf($notCustomerSite),
                'string',
                'max:255',
            ],
            'postal_code' => [
                Rule::requiredIf($customerSite),
                Rule::prohibitedIf($notCustomerSite),
                'string',
                'max:255',
            ],
            'country' => [
                Rule::requiredIf($customerSite),
                Rule::prohibitedIf($notCustomerSite),
                'string',
                'size:2',
                'regex:/^[A-Za-z]{2}$/',
            ],
            'bookable_service_id' => ['prohibited'],
            'scheduling_host_id' => ['prohibited'],
            'ends_at' => ['prohibited'],
            'capacity' => ['prohibited'],
            'remaining_capacity' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'source_window_ids' => ['prohibited'],
            'location_type' => ['prohibited'],
            'location_details' => ['prohibited'],
            'formatted_address' => ['prohibited'],
            'latitude' => ['prohibited'],
            'longitude' => ['prohibited'],
            'timezone' => ['prohibited'],
            'precision' => ['prohibited'],
            'confidence' => ['prohibited'],
            'provider' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function customerSiteAddress(): array
    {
        if (! $this->requiresCustomerSiteAddress()) {
            return [];
        }

        return [
            'address_line_1' => (string) $this->validated('address_line_1'),
            'address_line_2' => $this->nullableValidatedString('address_line_2'),
            'city' => (string) $this->validated('city'),
            'region' => (string) $this->validated('region'),
            'postal_code' => (string) $this->validated('postal_code'),
            'country' => (string) $this->validated('country'),
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

    private function requiresCustomerSiteAddress(): bool
    {
        if ($this->customerSite !== null) {
            return $this->customerSite;
        }

        $serviceKey = $this->route('serviceKey');

        if (! is_string($serviceKey) || trim($serviceKey) === '') {
            return $this->customerSite = false;
        }

        return $this->customerSite = BookableService::query()
            ->where('key', trim($serviceKey))
            ->where('status', BookableService::STATUS_ACTIVE)
            ->where('is_public', true)
            ->whereNull('deleted_at')
            ->where('location_type', BookableService::LOCATION_TYPE_CUSTOMER_SITE)
            ->exists();
    }

    private function nullableValidatedString(string $field): ?string
    {
        $value = $this->validated($field);

        if (! is_string($value)) {
            return null;
        }

        return trim($value) !== '' ? $value : null;
    }
}