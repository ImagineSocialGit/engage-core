<?php

namespace App\Modules\Events\Requests;

use App\Modules\Events\Enums\EventAttendanceMode;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type_key' => ['nullable', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'attendance_mode' => ['required', Rule::enum(EventAttendanceMode::class)],
            'starts_at_local' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at_local' => [
                'nullable',
                'date_format:Y-m-d\\TH:i',
                'after:starts_at_local',
            ],
            'timezone' => [
                'required',
                'string',
                Rule::in(DateTimeZone::listIdentifiers()),
            ],
            'announcement_at_local' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'country' => [
                'nullable',
                'string',
                'size:2',
                'regex:/^[A-Za-z]{2}$/',
            ],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function eventAttributes(): array
    {
        $validated = $this->validated();
        $timezone = trim((string) $validated['timezone']);

        return [
            'type_key' => $this->nullableString($validated['type_key'] ?? null),
            'title' => trim((string) $validated['title']),
            'description' => $this->nullableString($validated['description'] ?? null),
            'attendance_mode' => (string) $validated['attendance_mode'],
            'starts_at' => $this->toUtc(
                (string) $validated['starts_at_local'],
                $timezone,
            ),
            'ends_at' => $this->toUtc(
                $validated['ends_at_local'] ?? null,
                $timezone,
            ),
            'timezone' => $timezone,
            'announcement_at' => $this->toUtc(
                $validated['announcement_at_local'] ?? null,
                $timezone,
            ),
            'venue_name' => $this->nullableString($validated['venue_name'] ?? null),
            'address_line_1' => $this->nullableString($validated['address_line_1'] ?? null),
            'address_line_2' => $this->nullableString($validated['address_line_2'] ?? null),
            'city' => $this->nullableString($validated['city'] ?? null),
            'region' => $this->nullableString($validated['region'] ?? null),
            'postal_code' => $this->nullableString($validated['postal_code'] ?? null),
            'country' => ($country = $this->nullableString($validated['country'] ?? null)) !== null
                ? strtoupper($country)
                : null,
        ];
    }

    private function toUtc(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            'Y-m-d\\TH:i',
            trim($value),
            $timezone,
        )->utc();
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