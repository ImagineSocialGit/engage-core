<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Data\CampaignCreationOption;

final class CampaignCreationGuide
{
    /** @return array<int, CampaignCreationOption> */
    public function options(): array
    {
        return [
            new CampaignCreationOption(
                key: 'lead_nurture',
                label: 'Lead nurture',
                description: 'Follow up with prospects over time while they are deciding what to do next.',
                namePlaceholder: 'New lead nurture',
            ),
            new CampaignCreationOption(
                key: 'client_follow_up',
                label: 'Client follow-up',
                description: 'Stay in touch with customers or clients after the main transaction or service.',
                namePlaceholder: 'Client follow-up',
            ),
            new CampaignCreationOption(
                key: 'reengagement',
                label: 'Re-engagement',
                description: 'Reconnect with older contacts who have gone quiet or stopped responding.',
                namePlaceholder: 'Re-engagement campaign',
            ),
            new CampaignCreationOption(
                key: 'custom',
                label: 'Something else',
                description: 'Start a general-purpose follow-up sequence and shape the audience, timing, and messages yourself.',
                namePlaceholder: 'Custom campaign',
            ),
        ];
    }

    public function find(mixed $key): ?CampaignCreationOption
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $key = trim($key);

        foreach ($this->options() as $option) {
            if ($option->key === $key) {
                return $option;
            }
        }

        return null;
    }

    /** @return array<int, array{key: string, state: string, editable: bool}> */
    public function builderStages(): array
    {
        return [
            ['key' => 'start', 'state' => 'configured', 'editable' => false],
            ['key' => 'schedule', 'state' => 'configured', 'editable' => false],
            ['key' => 'messages', 'state' => 'configured', 'editable' => true],
            ['key' => 'review', 'state' => 'inactive', 'editable' => false],
        ];
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_map(
            static fn (CampaignCreationOption $option): string => $option->key,
            $this->options(),
        );
    }
}