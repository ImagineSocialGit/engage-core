<?php

namespace App\Modules\Events\Requests;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RescheduleEventRequest extends FormRequest
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
        ];
    }

    public function startsAt(): CarbonInterface
    {
        return $this->toUtc((string) $this->validated('starts_at_local'));
    }

    public function endsAt(): ?CarbonInterface
    {
        $value = $this->validated('ends_at_local');

        return is_string($value) && trim($value) !== ''
            ? $this->toUtc($value)
            : null;
    }

    public function timezone(): string
    {
        return trim((string) $this->validated('timezone'));
    }

    private function toUtc(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d\\TH:i',
            trim($value),
            $this->timezone(),
        )->utc();
    }
}