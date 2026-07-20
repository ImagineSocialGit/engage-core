<?php

namespace App\Modules\Webinars\Services\Dashboard;

use App\Models\DashboardAcknowledgement;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebinarActivityDashboardPanelProvider implements DashboardPanelProvider
{
    public function key(): string
    {
        return 'webinars.activity';
    }

    public function module(): string
    {
        return 'webinars';
    }

    /**
     * @return array<string, mixed>
     */
    public function panel(Request $request): array
    {
        $acknowledgedIds = $this->acknowledgedItemKeys(
            $request,
            DashboardAcknowledgement::TYPE_WEBINAR_REGISTRATION,
        );

        $attentionQuery = WebinarRegistration::query()
            ->with(['contact', 'webinar.webinarSeries'])
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('meta->registration_finalization->status', [
                        'failed',
                        'reconciliation_required',
                    ])
                    ->orWhere(
                        'meta->provider_sync->status',
                        'reconciliation_required',
                    );
            });

        $attentionCount = (clone $attentionQuery)->count();
        $attentionRegistrations = (clone $attentionQuery)
            ->latest('updated_at')
            ->latest('id')
            ->limit(8)
            ->get();

        $recentQuery = WebinarRegistration::query()
            ->with(['contact', 'webinar.webinarSeries'])
            ->where('registered_at', '>=', now()->subDays(7))
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->whereNull('meta->registration_finalization->status')
                            ->orWhereNotIn('meta->registration_finalization->status', [
                                'failed',
                                'reconciliation_required',
                            ]);
                    })
                    ->where(function (Builder $query): void {
                        $query
                            ->whereNull('meta->provider_sync->status')
                            ->orWhere(
                                'meta->provider_sync->status',
                                '!=',
                                'reconciliation_required',
                            );
                    });
            })
            ->when($acknowledgedIds !== [], fn (Builder $query) => $query->whereNotIn('id', $acknowledgedIds));

        $recentCount = (clone $recentQuery)->count();
        $recentRegistrations = (clone $recentQuery)
            ->latest('registered_at')
            ->latest('id')
            ->limit(max(0, 8 - $attentionRegistrations->count()))
            ->get();

        $items = $attentionRegistrations
            ->map(fn (WebinarRegistration $registration): array => $this->recoveryItem($registration))
            ->concat($recentRegistrations->map(
                fn (WebinarRegistration $registration): array => $this->webinarRegistrationItem($registration),
            ))
            ->values();

        return [
            'key' => $this->key(),
            'module' => $this->module(),
            'slot' => $attentionCount > 0 ? 'immediate_work' : 'context',
            'priority' => $attentionCount > 0 ? 140 : 50,
            'order' => 10,
            'view' => 'webinar_activity',
            'title' => $attentionCount > 0
                ? 'Webinar registration recovery'
                : 'Webinar activity',
            'description' => $attentionCount > 0
                ? 'Registration finalization failures require operator review before confirmations can continue.'
                : 'Supporting context for recent registrations. This is not the main daily triage list.',
            'empty_title' => 'No new webinar activity to review.',
            'empty_description' => 'Recent signups will appear here as context beneath the main work panels.',
            'summary_label' => $attentionCount > 0
                ? 'webinar registration failures'
                : 'webinar updates',
            'count' => $attentionCount + $recentCount,
            'attention_count' => $attentionCount,
            'hide_when_empty' => true,
            'items' => $items,
            'actions' => module_enabled('webinars') ? array_values(array_filter([
                $attentionCount > 0 ? [
                    'label' => 'Resolve registration failures',
                    'href' => route('crm.webinar-series.index', ['attention' => 1]),
                ] : null,
                [
                    'label' => 'View webinars',
                    'href' => route('crm.webinar-series.index'),
                ],
            ])) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recoveryItem(WebinarRegistration $registration): array
    {
        $contact = $registration->contact;
        $webinar = $registration->webinar;
        $contactName = $contact
            ? $this->contactName($contact)
            : 'Registration #'.$registration->getKey();
        $status = data_get(
            $registration->meta,
            'provider_sync.status',
        ) === 'reconciliation_required'
            ? 'reconciliation_required'
            : (string) data_get(
                $registration->meta,
                'registration_finalization.status',
                'failed',
            );
        $reason = (string) data_get(
            $registration->meta,
            'registration_finalization.failure_reason',
            'unknown_failure',
        );

        return [
            'key' => (string) $registration->id,
            'type' => 'webinar_registration_finalization',
            'sort_at' => $registration->updated_at,
            'label' => $status === 'reconciliation_required'
                ? 'Provider verification required'
                : 'Finalization failed',
            'tone' => 'amber',
            'title' => $contactName.' needs registration recovery',
            'subtitle' => trim(implode(' · ', array_filter([
                $webinar?->title,
                Str::headline($status),
            ]))),
            'description' => Str::headline($reason),
            'href' => route('crm.webinar-series.index', ['attention' => 1]),
            'action_label' => 'Review recovery',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webinarRegistrationItem(WebinarRegistration $registration): array
    {
        $contact = $registration->contact;
        $webinar = $registration->webinar;
        $contactName = $contact ? $this->contactName($contact) : 'A '.config('contacts.labels.singular');

        return [
            'key' => (string) $registration->id,
            'type' => DashboardAcknowledgement::TYPE_WEBINAR_REGISTRATION,
            'sort_at' => $registration->registered_at ?? $registration->created_at,
            'label' => 'Webinar signup',
            'tone' => 'emerald',
            'title' => $contactName.' registered',
            'subtitle' => trim(implode(' · ', array_filter([
                $webinar?->title,
                $this->dateLabel($registration->registered_at),
            ]))),
            'description' => $webinar?->starts_at
                ? 'Upcoming: '.$this->dateLabel($webinar->starts_at)
                : 'A new webinar registration came in.',
            'href' => $contact ? route('crm.contacts.show', $contact) : null,
            'action_label' => $contact ? 'Open '.config('contacts.labels.singular') : 'Review webinar',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function acknowledgedItemKeys(Request $request, string $itemType): array
    {
        $userId = $request->user()?->id;

        if (! $userId) {
            return [];
        }

        return DashboardAcknowledgement::query()
            ->active()
            ->where('user_id', $userId)
            ->where('surface', DashboardAcknowledgement::SURFACE_CRM_DASHBOARD)
            ->where('item_type', DashboardAcknowledgement::normalizeItemType($itemType))
            ->pluck('item_key')
            ->values()
            ->all();
    }

    private function dateLabel(mixed $date): ?string
    {
        return $date?->copy()
            ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
            ->format('M j, g:i A');
    }

    private function contactName(Contact $contact): string
    {
        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name).' '.trim((string) $contact->last_name)
        )));

        return $name !== '' ? $name : ($contact->email ?: Str::title(config('contacts.labels.singular')).' #'.$contact->id);
    }
}