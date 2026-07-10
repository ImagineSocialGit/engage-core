<?php

namespace App\Modules\Messaging\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class MessageDedupeKeyBuilder
{
    /**
     * @param array<string, mixed> $definition
     */
    public function build(
        Model $recipient,
        array $definition,
        ?Model $context,
        Carbon $sendAt,
        ?Model $behaviorOwner = null,
    ): string {
        $identity = [
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'channel' => $definition['channel'] ?? null,
            'purpose' => $definition['purpose'] ?? null,
            'scope' => $definition['scope'] ?? null,
            'message_type' => $definition['message_type'] ?? null,
            'campaign_key' => $definition['campaign_key'] ?? null,
            'campaign_step' => $definition['step'] ?? null,
            'campaign_step_variant_key' => $definition['variant']
                ?? $definition['campaign_step_variant_key']
                ?? data_get($definition, 'meta.campaign.campaign_step_variant_key'),
            'campaign_step_variant_source_config_path' => $definition['campaign_step_variant_source_config_path']
                ?? data_get($definition, 'meta.campaign.campaign_step_variant_source_config_path'),
            'definition_config_path' => $definition['config_path']
                ?? $definition['definition_config_path']
                ?? data_get($definition, 'meta.definition_config_path'),
            'message_template_preset_id' => data_get($definition, 'meta.message_template_preset.id'),
            'message_template_assignment_id' => data_get($definition, 'meta.message_template_preset.assignment_id'),
            'behavior_owner_type' => $behaviorOwner?->getMorphClass(),
            'behavior_owner_id' => $behaviorOwner?->getKey(),
            'send_at' => $sendAt->toISOString(),
            'context_type' => $context?->getMorphClass(),
            'context_id' => $context?->getKey(),
        ];

        $fingerprint = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        return implode(':', array_filter([
            'message',
            class_basename($recipient->getMorphClass()),
            $recipient->getKey(),
            $definition['channel'] ?? null,
            $definition['purpose'] ?? null,
            $definition['scope'] ?? null,
            $definition['message_type'] ?? null,
            substr($fingerprint, 0, 32),
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
