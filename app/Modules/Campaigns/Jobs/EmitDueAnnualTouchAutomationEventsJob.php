<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Core\Models\Contact;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use App\Support\ModuleFacts\Data\ModuleFactQuery;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;
use App\Support\ModuleFacts\ModuleFactRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

final class EmitDueAnnualTouchAutomationEventsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 3600;

    public function __construct(public readonly ?string $date = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'campaign-annual-touch-events:'.($this->date ?? now()->toDateString());
    }

    public function handle(
        ModuleFactRegistry $moduleFacts,
        AutomationEventOutbox $outbox,
    ): void {
        $timezone = (string) config('client.timezone', config('app.timezone', 'UTC'));
        $today = $this->date !== null
            ? Carbon::parse($this->date, $timezone)->startOfDay()
            : now($timezone)->startOfDay();

        foreach ($moduleFacts->matching(
            Contact::class,
            ModuleFactType::Date,
            ModuleFactCapability::Annualizable,
        ) as $fact) {
            $query = Contact::query();
            $moduleFacts->apply(
                $fact->key,
                $query,
                ModuleFactQuery::annualMonthDay($today),
            );

            $query
                ->reorder()
                ->select('contacts.*')
                ->distinct()
                ->chunkById(250, function ($contacts) use ($fact, $moduleFacts, $outbox, $today): void {
                    foreach ($contacts as $contact) {
                        $resolved = $moduleFacts->resolve($fact->key, $contact);
                        $stored = $resolved instanceof \DateTimeInterface
                            ? Carbon::instance($resolved)
                            : null;

                        if (! $stored instanceof Carbon) {
                            continue;
                        }

                        $number = max(1, (int) $today->year - (int) $stored->year);

                        $outbox->record(
                            AutomationEventData::forSubject(
                                eventKey: 'campaign_touch.annual_date_due',
                                subject: $contact,
                                contactId: (int) $contact->getKey(),
                                occurredAt: $today->copy()->utc(),
                                payload: [
                                    'annual_date' => [
                                        'source_key' => $fact->key,
                                        'source_label' => $fact->label,
                                        'source_date' => $stored->toDateString(),
                                        'occurrence_number' => $number,
                                        'occurrence_ordinal' => $this->ordinal($number),
                                    ],
                                ],
                                meta: ['source_module' => $fact->owner],
                            ),
                            idempotencyKey: implode(':', [
                                'campaign-annual-touch-date',
                                $fact->key,
                                $contact->getKey(),
                                $today->year,
                            ]),
                        );
                    }
                }, 'contacts.id', 'id');
        }
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
}