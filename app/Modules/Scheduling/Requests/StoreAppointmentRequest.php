<?php

namespace App\Modules\Scheduling\Requests;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
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
            'starts_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid', 'max:191'],
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
            'idempotency_key.uuid' => 'The appointment replay key is invalid. Refresh the page and try again.',
        ];
    }
}