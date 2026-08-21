<?php

namespace App\Modules\Campaigns\Services\ContactShow;

use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use Illuminate\Support\Str;

class ContactCampaignsVisibilityDataProvider implements ContactShowDataProvider
{
    /** @return array<string, mixed> */
    public function dataFor(Contact $contact): array
    {
        $enrollments = CampaignEnrollment::query()
            ->with([
                'campaign',
                'messageChainEnrollment.currentMessageChainStep',
                'messageChainEnrollment.latestScheduledMessage',
            ])
            ->where('contact_id', $contact->id)
            ->latest('started_at')
            ->latest('id')
            ->limit(12)
            ->get()
            ->sortBy(fn (CampaignEnrollment $enrollment): int => $this->statusOrder($enrollment->messageChainEnrollment?->status))
            ->take(6)
            ->values();

        return [
            'contactVisibilitySections' => [
                'campaigns' => [
                    'title' => 'Follow-up sequences',
                    'module' => 'campaigns',
                    'description' => 'Current and recent follow-up sequence activity.',
                    'empty' => 'No follow-up sequences found.',
                    'items' => $enrollments->map(fn (CampaignEnrollment $enrollment): array => $this->item($enrollment))->all(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function item(CampaignEnrollment $enrollment): array
    {
        $chain = $enrollment->messageChainEnrollment;
        $lastMessage = $chain?->latestScheduledMessage;

        return [
            'title' => $this->campaignName($enrollment),
            'subtitle' => null,
            'status' => $this->label($chain?->status) ?? 'Unknown',
            'meta' => [
                'Current Step' => $this->stepLabel($chain?->currentMessageChainStep),
                'Started' => $this->date($chain?->started_at ?? $enrollment->started_at),
                'Next Action' => $this->date($chain?->next_action_at),
                'Exited' => $this->date($chain?->exited_at ?? $chain?->completed_at ?? $chain?->cancelled_at),
                'Exit Reason' => $this->label($chain?->exit_reason_code),
                'Last Message' => $lastMessage
                    ? $this->label($lastMessage->status).' '.$this->date($lastMessage->send_at)
                    : null,
            ],
        ];
    }

    private function campaignName(CampaignEnrollment $enrollment): string
    {
        $campaign = $enrollment->campaign;

        foreach (['name', 'title'] as $attribute) {
            $value = $campaign->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->label($campaign?->key ?? $enrollment->campaign_key)
            ?? 'Follow-up sequence';
    }

    private function stepLabel(?MessageChainStep $step): ?string
    {
        if (! $step instanceof MessageChainStep) {
            return null;
        }

        foreach (['name', 'key'] as $attribute) {
            $value = $step->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'Step #'.$step->getKey();
    }

    private function statusOrder(?string $status): int
    {
        return match ($status) {
            MessageChainEnrollment::STATUS_ACTIVE => 0,
            MessageChainEnrollment::STATUS_PAUSED => 1,
            MessageChainEnrollment::STATUS_COMPLETED => 2,
            MessageChainEnrollment::STATUS_EXITED => 3,
            MessageChainEnrollment::STATUS_CANCELLED => 4,
            default => 5,
        };
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