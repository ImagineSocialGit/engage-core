<?php

namespace App\Modules\Scheduling\Requests;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingLocalDateTimeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StoreAppointmentRequest extends FormRequest
{
    private ?bool $customerSite = null;
    private ?BookableService $resolvedService = null;
    private bool $serviceResolved = false;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $hostId = $this->input('scheduling_host_id');

        $this->merge([
            'scheduling_host_id' => $hostId === '' ? null : $hostId,
            'idempotency_key' => trim((string) $this->input('idempotency_key')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $customerSite = fn (): bool => $this->requiresCustomerSiteAddress();
        $notCustomerSite = fn (): bool => ! $this->requiresCustomerSiteAddress();
        $range = fn (): bool => $this->usesRangeDuration();
        $fixed = fn (): bool => ! $this->usesRangeDuration();

        return [
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id'),
            ],
            'bookable_service_id' => [
                'required',
                'integer',
                Rule::exists('bookable_services', 'id')
                    ->where(fn ($query) => $query
                        ->where('status', BookableService::STATUS_ACTIVE)
                        ->whereNull('deleted_at')),
            ],
            'scheduling_host_id' => [
                'nullable',
                'integer',
                Rule::exists('scheduling_hosts', 'id')
                    ->where(fn ($query) => $query
                        ->where('status', SchedulingHost::STATUS_ACTIVE)
                        ->whereNull('deleted_at')),
            ],
            'starts_at' => [
                Rule::requiredIf($fixed),
                Rule::prohibitedIf($range),
                'string',
                'max:64',
                'date',
            ],
            'range_starts_at' => [
                Rule::requiredIf($range),
                Rule::prohibitedIf($fixed),
                'string',
                'max:16',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
            ],
            'range_ends_at' => [
                Rule::requiredIf($range),
                Rule::prohibitedIf($fixed),
                'string',
                'max:16',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
            ],
            'idempotency_key' => ['required', 'uuid', 'max:191'],
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
            'ends_at' => ['prohibited'],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_id.required' => 'Choose a contact for the appointment.',
            'contact_id.exists' => 'The selected contact could not be found.',
            'bookable_service_id.required' => 'Choose a service.',
            'bookable_service_id.exists' => 'The selected service is not available.',
            'scheduling_host_id.exists' => 'The selected host is not available.',
            'starts_at.required' => 'Choose an available appointment time.',
            'starts_at.date' => 'The selected appointment time is invalid.',
            'range_starts_at.required' => 'Enter the check-in date and time.',
            'range_starts_at.regex' => 'The check-in date and time is invalid.',
            'range_ends_at.required' => 'Enter the check-out date and time.',
            'range_ends_at.regex' => 'The check-out date and time is invalid.',
            'idempotency_key.uuid' => 'The appointment replay key is invalid. Refresh the page and try again.',
            'address_line_1.required' => 'Enter the customer service address.',
            'city.required' => 'Enter the service city.',
            'region.required' => 'Enter the service state or region.',
            'postal_code.required' => 'Enter the service postal code.',
            'country.required' => 'Enter the two-letter service country code.',
        ];
    }

    private function usesRangeDuration(): bool
    {
        return $this->service()?->usesRangeDuration() ?? false;
    }

    private function requiresCustomerSiteAddress(): bool
    {
        if ($this->customerSite !== null) {
            return $this->customerSite;
        }

        return $this->customerSite = $this->service()?->location_type
            === BookableService::LOCATION_TYPE_CUSTOMER_SITE;
    }

    private function service(): ?BookableService
    {
        if ($this->serviceResolved) {
            return $this->resolvedService;
        }

        $this->serviceResolved = true;
        $serviceId = $this->input('bookable_service_id');

        if (! is_numeric($serviceId) || (int) $serviceId < 1) {
            return null;
        }

        return $this->resolvedService = BookableService::query()
            ->whereKey((int) $serviceId)
            ->where('status', BookableService::STATUS_ACTIVE)
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

    private function nullableValidatedString(string $field): ?string
    {
        $value = $this->validated($field);

        if (! is_string($value)) {
            return null;
        }

        return trim($value) !== '' ? $value : null;
    }
}