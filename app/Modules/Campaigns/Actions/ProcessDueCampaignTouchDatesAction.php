<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\CampaignTouchDate;
use App\Modules\Campaigns\Models\CampaignTouchDispatch;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Campaigns\Models\CampaignTouchVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\Campaigns\Services\CampaignAnnualTouchAudienceService;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Support\ModuleFacts\Data\ModuleFactDefinition;
use App\Support\ModuleFacts\Data\ModuleFactQuery;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;
use App\Support\ModuleFacts\ModuleFactRegistry;

class ProcessDueCampaignTouchDatesAction
{
    public const DISPATCH_KEY = 'campaign_touch_due';

    private const DEFAULT_SEND_TIME = '09:00:00';
    private const MAX_DISPATCHES_PER_RUN = 500;

    public function __construct(
        private readonly CampaignAnnualTouchAudienceService $annualTouchAudience,
        private readonly DispatchMessageAction $dispatchMessage,
        private readonly ModuleFactRegistry $moduleFacts,
    ) {}

    /**
     * @return array{
     *     evaluated: int,
     *     scheduled: int,
     *     skipped: int
     * }
     */
    public function handle(Carbon|string|null $at = null): array
    {
        $timezone = (string) config(
            'client.timezone',
            config('app.timezone', 'UTC'),
        );

        $now = $at instanceof Carbon
            ? $at->copy()->timezone($timezone)
            : ($at !== null
                ? Carbon::parse($at, $timezone)->timezone($timezone)
                : now($timezone));

        $remaining = self::MAX_DISPATCHES_PER_RUN;
        $evaluated = 0;
        $scheduled = 0;
        $skipped = 0;

        $programs = CampaignTouchProgram::query()
            ->where('is_active', true)
            ->where('recurrence', CampaignTouchProgram::RECURRENCE_ANNUAL)
            ->with([
                'touchDates' => fn ($query) => $query->where('is_active', true),
                'touchDates.variants' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with('messageTemplatePreset.canonicalTemplate.currentVersion'),
            ])
            ->orderBy('id')
            ->get();

        foreach ($programs as $program) {
            foreach ($program->touchDates as $touchDate) {
                if ($remaining < 1) {
                    break 2;
                }

                $sourceDate = $this->sourceDateForToday($touchDate, $now);

                if (! $sourceDate instanceof Carbon
                    || ! $this->withinProgramWindow($program, $sourceDate, $timezone)
                ) {
                    continue;
                }

                $dueAt = $this->dueAt($touchDate, $now, $timezone);

                if ($now->lt($dueAt)) {
                    continue;
                }

                foreach ($touchDate->variants as $variant) {
                    if ($remaining < 1) {
                        break 3;
                    }

                    $preset = $variant->messageTemplatePreset;

                    if (! $preset instanceof MessageTemplatePreset
                        || ! $preset->isActive()
                        || ! $this->templateMatchesVariant($preset, $variant)
                    ) {
                        continue;
                    }

                    $contacts = $this->dueContacts(
                        program: $program,
                        touchDate: $touchDate,
                        variant: $variant,
                        sourceDate: $sourceDate,
                        occurrenceYear: (int) $sourceDate->year,
                        limit: $remaining,
                    )->get();

                    foreach ($contacts as $contact) {
                        $evaluated++;
                        $remaining--;

                        $message = $this->schedule(
                            contact: $contact,
                            program: $program,
                            touchDate: $touchDate,
                            variant: $variant,
                            preset: $preset,
                            dueAt: $dueAt,
                            occurrenceYear: (int) $sourceDate->year,
                            now: $now,
                        );

                        CampaignTouchDispatch::query()->updateOrCreate(
                            [
                                'campaign_touch_variant_id' => $variant->getKey(),
                                'contact_id' => $contact->getKey(),
                                'occurrence_year' => (int) $sourceDate->year,
                            ],
                            [
                                'due_at' => $dueAt->copy()->utc(),
                                'scheduled_message_id' => $message?->getKey(),
                                'status' => $message
                                    ? CampaignTouchDispatch::STATUS_SCHEDULED
                                    : CampaignTouchDispatch::STATUS_SKIPPED,
                                'reason' => $message
                                    ? null
                                    : 'messaging_planning_gate_denied',
                                'meta' => [
                                    'campaign_touch_program_id' => $program->getKey(),
                                    'campaign_touch_program_key' => $program->key,
                                    'campaign_touch_date_id' => $touchDate->getKey(),
                                    'source_type' => $touchDate->source_type,
                                    'source_key' => $touchDate->source_key,
                                ],
                            ],
                        );

                        if ($message) {
                            $scheduled++;
                        } else {
                            $skipped++;
                        }

                        if ($remaining < 1) {
                            break;
                        }
                    }
                }
            }
        }

        return [
            'evaluated' => $evaluated,
            'scheduled' => $scheduled,
            'skipped' => $skipped,
        ];
    }

    private function sourceDateForToday(
        CampaignTouchDate $touchDate,
        Carbon $now,
    ): ?Carbon {
        $sourceDate = $now->copy()
            ->startOfDay()
            ->subDays((int) $touchDate->offset_days);

        if (in_array($touchDate->source_type, [
            CampaignTouchDate::SOURCE_CONTACT_FIELD,
            CampaignTouchDate::SOURCE_REGISTERED_DATE,
        ], true)) {
            return is_string($touchDate->source_key)
                && $this->annualDateFact($touchDate->source_key) instanceof ModuleFactDefinition
                    ? $sourceDate
                    : null;
        }

        if ($touchDate->source_type !== CampaignTouchDate::SOURCE_FIXED_DATE
            || ! is_numeric($touchDate->month)
            || ! is_numeric($touchDate->day)
        ) {
            return null;
        }

        return $this->matchesMonthDay(
            month: (int) $touchDate->month,
            day: (int) $touchDate->day,
            date: $sourceDate,
        )
            ? $sourceDate
            : null;
    }

    private function withinProgramWindow(
        CampaignTouchProgram $program,
        Carbon $sourceDate,
        string $timezone,
    ): bool {
        $repeatYears = max(1, (int) $program->repeat_years);
        $startsOn = $program->starts_on instanceof \DateTimeInterface
            ? Carbon::instance($program->starts_on)->timezone($timezone)->startOfDay()
            : ($program->created_at instanceof \DateTimeInterface
                ? Carbon::instance($program->created_at)->timezone($timezone)->startOfDay()
                : $sourceDate->copy()->startOfDay());

        $endsBefore = $startsOn->copy()->addYears($repeatYears);

        return $sourceDate->gte($startsOn)
            && $sourceDate->lt($endsBefore);
    }

    private function dueAt(
        CampaignTouchDate $touchDate,
        Carbon $now,
        string $timezone,
    ): Carbon {
        $sendTime = is_string($touchDate->send_time)
            && trim($touchDate->send_time) !== ''
                ? trim($touchDate->send_time)
                : self::DEFAULT_SEND_TIME;

        return Carbon::parse(
            $now->toDateString().' '.$sendTime,
            $timezone,
        );
    }

    /**
     * @return Builder<Contact>
     */
    private function dueContacts(
        CampaignTouchProgram $program,
        CampaignTouchDate $touchDate,
        CampaignTouchVariant $variant,
        Carbon $sourceDate,
        int $occurrenceYear,
        int $limit,
    ): Builder {
        $query = $this->audienceQuery($program);

        if (in_array($touchDate->source_type, [
            CampaignTouchDate::SOURCE_CONTACT_FIELD,
            CampaignTouchDate::SOURCE_REGISTERED_DATE,
        ], true)) {
            $this->applyAnnualDateSourceFilter(
                query: $query,
                sourceKey: (string) $touchDate->source_key,
                sourceDate: $sourceDate,
            );
        }

        return $query
            ->whereNotExists(function ($query) use ($variant, $occurrenceYear): void {
                $query
                    ->selectRaw('1')
                    ->from('campaign_touch_dispatches')
                    ->whereColumn(
                        'campaign_touch_dispatches.contact_id',
                        'contacts.id',
                    )
                    ->where(
                        'campaign_touch_dispatches.campaign_touch_variant_id',
                        $variant->getKey(),
                    )
                    ->where(
                        'campaign_touch_dispatches.occurrence_year',
                        $occurrenceYear,
                    );
            })
            ->reorder()
            ->select('contacts.*')
            ->distinct()
            ->orderBy('contacts.id')
            ->limit(max(1, $limit));
    }

    /**
     * @return Builder<Contact>
     */
    private function audienceQuery(CampaignTouchProgram $program): Builder
    {
        return $this->annualTouchAudience->queryForProgram($program);
    }

    /**
     * @param Builder<Contact> $query
     */
    private function applyAnnualDateSourceFilter(
        Builder $query,
        string $sourceKey,
        Carbon $sourceDate,
    ): void {
        if (! $this->annualDateFact($sourceKey)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->moduleFacts->apply(
            $sourceKey,
            $query,
            ModuleFactQuery::annualMonthDay($sourceDate),
        );
    }

    private function matchesMonthDay(
        int $month,
        int $day,
        Carbon $date,
    ): bool {
        if ($date->month === $month && $date->day === $day) {
            return true;
        }

        return $month === 2
            && $day === 29
            && $date->month === 2
            && $date->day === 28
            && ! $date->isLeapYear();
    }

    private function templateMatchesVariant(
        MessageTemplatePreset $preset,
        CampaignTouchVariant $variant,
    ): bool {
        return $preset->channel === $variant->channel
            && $preset->purpose === $variant->purpose
            && $preset->scope === $variant->scope;
    }

    private function schedule(
        Contact $contact,
        CampaignTouchProgram $program,
        CampaignTouchDate $touchDate,
        CampaignTouchVariant $variant,
        MessageTemplatePreset $preset,
        Carbon $dueAt,
        int $occurrenceYear,
        Carbon $now,
    ): ?\App\Modules\Messaging\Models\ScheduledMessage {
        $definition = $preset->toMessageDefinition();
        $definition['dispatch_keys'] = [self::DISPATCH_KEY];

        $messages = $this->dispatchMessage->handle(
            recipient: $contact,
            channel: $variant->channel,
            purpose: $variant->purpose,
            scope: $variant->scope,
            dispatchKeys: self::DISPATCH_KEY,
            payload: [
                'tokens' => [
                    'annual_touch' => $this->occurrenceTokens(
                        contact: $contact,
                        program: $program,
                        touchDate: $touchDate,
                        occurrenceYear: $occurrenceYear,
                    ),
                ],
            ],
            context: $program,
            triggeredAt: $now,
            sendAt: $now,
            meta: [
                'campaign_touch' => [
                    'program_id' => $program->getKey(),
                    'program_key' => $program->key,
                    'date_id' => $touchDate->getKey(),
                    'date_key' => $touchDate->key,
                    'variant_id' => $variant->getKey(),
                    'variant_key' => $variant->key,
                    'occurrence_year' => $occurrenceYear,
                    'due_at' => $dueAt->toISOString(),
                ],
            ],
            definitions: [$definition],
            behaviorOwner: $touchDate,
            occurrenceKey: implode(':', [
                'campaign_touch',
                $variant->getKey(),
                $contact->getKey(),
                $occurrenceYear,
            ]),
        );

        return $messages[0] ?? null;
    }

    /** @return array{occurrence_number: int, occurrence_ordinal: string, source_date: string} */
    private function occurrenceTokens(
        Contact $contact,
        CampaignTouchProgram $program,
        CampaignTouchDate $touchDate,
        int $occurrenceYear,
    ): array {
        $number = 1;
        $sourceDate = null;

        if (in_array($touchDate->source_type, [
            CampaignTouchDate::SOURCE_CONTACT_FIELD,
            CampaignTouchDate::SOURCE_REGISTERED_DATE,
        ], true)
            && is_string($touchDate->source_key)
            && $this->annualDateFact($touchDate->source_key) instanceof ModuleFactDefinition
        ) {
            $fact = $this->moduleFacts->require($touchDate->source_key);
            $resolved = $this->moduleFacts->resolve($fact->key, $contact);
            $sourceDate = $resolved instanceof \DateTimeInterface
                ? Carbon::instance($resolved)
                : null;

            if (! $sourceDate instanceof Carbon) {
                throw new \LogicException(
                    "Annual date fact [{$fact->key}] matched contact [{$contact->getKey()}] but did not resolve a source date.",
                );
            }

            $number = max(1, $occurrenceYear - (int) $sourceDate->year);
        } else {
            $anchor = $program->starts_on instanceof \DateTimeInterface
                ? Carbon::instance($program->starts_on)
                : ($program->created_at instanceof \DateTimeInterface
                    ? Carbon::instance($program->created_at)
                    : Carbon::createSafe($occurrenceYear, 1, 1));
            $number = max(1, $occurrenceYear - (int) $anchor->year + 1);
            $month = (int) $touchDate->month;
            $day = min(
                (int) $touchDate->day,
                Carbon::createSafe($occurrenceYear, $month, 1)->daysInMonth,
            );
            $sourceDate = Carbon::createSafe(
                $occurrenceYear,
                $month,
                $day,
            );
        }

        return [
            'occurrence_number' => $number,
            'occurrence_ordinal' => $this->ordinal($number),
            'source_date' => $sourceDate->toDateString(),
        ];
    }

    private function ordinal(int $number): string
    {
        $mod100 = $number % 100;
        $suffix = in_array($mod100, [11, 12, 13], true)
            ? 'th'
            : match ($number % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            };

        return $number.$suffix;
    }

    private function annualDateFact(string $key): ?ModuleFactDefinition
    {
        $definition = $this->moduleFacts->find($key);

        return $definition instanceof ModuleFactDefinition
            && $definition->subject === Contact::class
            && $definition->type === ModuleFactType::Date
            && $definition->has(ModuleFactCapability::Annualizable)
            && $definition->queryResolver !== null
                ? $definition
                : null;
    }
}