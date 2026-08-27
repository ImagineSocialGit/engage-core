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
    public const ATTENDEE_MODE_CONTACT = 'contact';
    public const ATTENDEE_MODE_NEW_CONTACT = 'new_contact';
    public const ATTENDEE_MODE_GUEST = 'guest';

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
        $contactId = $this->input('contact_id');

        $this->merge([
            'attendee_mode' => trim((string) $this->input('attendee_mode')),
            'contact_id' => $contactId === '' ? null : $contactId,
            'scheduling_host_id' => $hostId === '' ? null : $hostId,
            'attendee_name' => $this->trimmedInput('attendee_name'),
            'attendee_email' => (($email = $this->trimmedInput('attendee_email')) !== null ? strtolower($email) : null),
            'attendee_phone' => $this->trimmedInput('attendee_phone'),
            'attendee_context' => $this->trimmedInput('attendee_context'),
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
        $contactMode = fn (): bool => $this->attendeeModeInput() === self::ATTENDEE_MODE_CONTACT;
        $notContactMode = fn (): bool => $this->attendeeModeInput() !== self::ATTENDEE_MODE_CONTACT;
        $newContactMode = fn (): bool => $this->attendeeModeInput() === self::ATTENDEE_MODE_NEW_CONTACT;
        $snapshotMode = fn (): bool => in_array(
            $this->attendeeModeInput(),
            [self::ATTENDEE_MODE_NEW_CONTACT, self::ATTENDEE_MODE_GUEST],
            true,
        );

        return [
            'attendee_mode' => [
                'required',
                Rule::in([
                    self::ATTENDEE_MODE_CONTACT,
                    self::ATTENDEE_MODE_NEW_CONTACT,
                    self::ATTENDEE_MODE_GUEST,
                ]),
            ],
            'contact_id' => [
                Rule::requiredIf($contactMode),
                Rule::prohibitedIf($notContactMode),
                'nullable',
                'integer',
                Rule::exists('contacts', 'id'),
            ],
            'attendee_name' => [
                Rule::requiredIf($snapshotMode),
                Rule::prohibitedIf($contactMode),
                'nullable',
                'string',
                'max:255',
            ],
            'attendee_email' => [
                Rule::requiredIf($newContactMode),
                Rule::prohibitedIf($contactMode),
                'nullable',
                'email:rfc',
                'max:255',
            ],
            'attendee_phone' => [
                Rule::prohibitedIf($contactMode),
                'nullable',
                'string',
                'max:255',
            ],
            'attendee_context' => [
                'nullable',
                'string',
                'max:5000',
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

    public function attendeeMode(): string
    {
        return (string) $this->validated('attendee_mode');
    }

    public function attendeeName(): ?string
    {
        return $this->nullableValidatedString('attendee_name');
    }

    public function attendeeEmail(): ?string
    {
        return $this->nullableValidatedString('attendee_email');
    }

    public function attendeePhone(): ?string
    {
        return $this->nullableValidatedString('attendee_phone');
    }

    public function attendeeContext(): ?string
    {
        return $this->nullableValidatedString('attendee_context');
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
            'attendee_mode.required' => 'Choose who this appointment is for.',
            'attendee_mode.in' => 'Choose a valid attendee option.',
            'contact_id.required' => 'Choose a Contact from the search results.',
            'contact_id.exists' => 'The selected Contact could not be found.',
            'attendee_name.required' => 'Enter the attendee name.',
            'attendee_email.required' => 'Enter an email address so the new person can be added to Contacts.',
            'attendee_email.email' => 'Enter a valid attendee email address.',
            'bookable_service_id.required' => 'Choose a service.',
            'bookable_service_id.exists' => 'The selected service is not available.',
            'scheduling_host_id.exists' => 'The selected staff member or provider is not available.',
            'starts_at.required' => 'Choose an available appointment start time.',
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

    private function attendeeModeInput(): string
    {
        return trim((string) $this->input('attendee_mode'));
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

    private function trimmedInput(string $field): ?string
    {
        $value = $this->input($field);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function nullableValidatedString(string $field): ?string
    {
        $value = $this->validated($field);

        if (! is_string($value)) {
            return null;
        }

        return trim($value) !== '' ? trim($value) : null;
    }
}