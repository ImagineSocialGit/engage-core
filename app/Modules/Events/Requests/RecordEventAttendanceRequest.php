<?php

namespace App\Modules\Events\Requests;

use App\Modules\Events\Enums\EventAttendanceStatus;
use App\Modules\Events\Models\Event;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordEventAttendanceRequest extends FormRequest
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
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'status' => ['required', Rule::enum(EventAttendanceStatus::class)],
            'observed_at_local' => ['nullable', 'date_format:Y-m-d\\TH:i'],
        ];
    }

    public function observedAt(Event $event): CarbonInterface
    {
        $value = $this->validated('observed_at_local');

        if (! is_string($value) || trim($value) === '') {
            return CarbonImmutable::now('UTC');
        }

        return CarbonImmutable::createFromFormat(
            'Y-m-d\\TH:i',
            trim($value),
            $event->timezone,
        )->utc();
    }
}