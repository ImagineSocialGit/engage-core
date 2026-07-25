<?php

namespace App\Modules\Scheduling\Requests;

use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleAppointmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reschedule_reason');

        $this->merge([
            'reschedule_reason' => is_string($reason)
                ? trim($reason)
                : $reason,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scheduling_host_id' => [
                'nullable',
                'integer',
                Rule::exists('scheduling_hosts', 'id')
                    ->where(fn ($query) => $query
                        ->where('status', SchedulingHost::STATUS_ACTIVE)
                        ->whereNull('deleted_at')),
            ],
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
            'reschedule_reason' => ['required', 'string', 'max:10000'],
            'preserve_confirmation' => ['sometimes', 'boolean'],
            'override_reschedule_notice' => ['sometimes', 'boolean'],
            'bookable_service_id' => ['prohibited'],
            'ends_at' => ['prohibited'],
            'status' => ['prohibited'],
            'offer_id' => ['prohibited'],
            'hold_id' => ['prohibited'],
            'rescheduled_from_id' => ['prohibited'],
            'source' => ['prohibited'],
            'actor_type' => ['prohibited'],
            'actor_id' => ['prohibited'],
            'lifecycle_event' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduling_host_id.exists' => 'The selected scheduling host is not available.',
            'starts_at.required' => 'Choose an available appointment time.',
            'starts_at.date' => 'The selected appointment time is invalid.',
            'starts_at.regex' => 'The selected appointment time must use the expected UTC format.',
            'idempotency_key.uuid' => 'The reschedule replay key is invalid. Refresh the page and try again.',
            'reschedule_reason.required' => 'Enter a reason for rescheduling the appointment.',
            'preserve_confirmation.boolean' => 'The confirmation-preservation choice is invalid.',
            'override_reschedule_notice.boolean' => 'The reschedule-notice override is invalid.',
            '*.prohibited' => 'Appointment reschedule state is server-owned.',
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

    public function reason(): string
    {
        return trim((string) $this->validated('reschedule_reason'));
    }

    public function hostId(): ?int
    {
        $hostId = $this->validated('scheduling_host_id');

        return is_numeric($hostId) ? (int) $hostId : null;
    }

    public function preserveConfirmation(): bool
    {
        return $this->boolean('preserve_confirmation');
    }

    public function overridesNotice(): bool
    {
        return $this->boolean('override_reschedule_notice');
    }
}