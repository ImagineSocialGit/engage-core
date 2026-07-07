<?php

namespace App\Modules\Campaigns\Services\ContactShow;

use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Str;

class ContactCampaignsVisibilityDataProvider implements ContactShowDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $enrollments = CampaignEnrollment::query()
            ->with([
                'campaign',
                'currentCampaignStep',
                'lastScheduledMessage',
            ])
            ->where('contact_id', $contact->id)
            ->orderByRaw("FIELD(status, 'active', 'paused', 'completed', 'cancelled')")
            ->latest('started_at')
            ->limit(6)
            ->get();

        return [
            'contactVisibilitySections' => [
                'campaigns' => [
                    'title' => 'Follow-up sequences',
                    'module' => 'campaigns',
                    'description' => 'Current and recent follow-up sequence activity.',
                    'empty' => 'No follow-up sequences found.',
                    'items' => $enrollments->map(fn (CampaignEnrollment $enrollment): array => [
                        'title' => $this->campaignName($enrollment),
                        'subtitle' => $enrollment->campaign_key,
                        'status' => Str::of($enrollment->status)->replace('_', ' ')->title()->toString(),
                        'meta' => [
                            'Current Step' => $this->stepLabel($enrollment),
                            'Channel' => $this->label($enrollment->channel),
                            'Purpose' => $this->label($enrollment->purpose),
                            'Started' => $this->date($enrollment->started_at),
                            'Exited' => $this->date($enrollment->exited_at),
                            'Exit Reason' => $this->label($enrollment->exit_reason),
                            'Last Message' => $enrollment->lastScheduledMessage
                                ? $this->label($enrollment->lastScheduledMessage->status).' '.$this->date($enrollment->lastScheduledMessage->send_at)
                                : null,
                        ],
                    ])->all(),
                ],
            ],
        ];
    }

    private function campaignName(CampaignEnrollment $enrollment): string
    {
        $campaign = $enrollment->campaign;

        foreach (['name', 'title', 'key'] as $attribute) {
            $value = $campaign->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $enrollment->campaign_key ?: 'Campaign #'.$enrollment->campaign_id;
    }

    private function stepLabel(CampaignEnrollment $enrollment): ?string
    {
        $step = $enrollment->currentCampaignStep;

        if (! $step) {
            return $enrollment->current_step ? 'Step '.$enrollment->current_step : null;
        }

        foreach (['name', 'title', 'key', 'message_type'] as $attribute) {
            $value = $step->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'Step '.$enrollment->current_step;
    }

    private function label(?string $value): ?string
    {
        return filled($value)
            ? Str::of($value)->replace('_', ' ')->title()->toString()
            : null;
    }

    private function date(mixed $date): ?string
    {
        return $date?->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A');
    }
}
