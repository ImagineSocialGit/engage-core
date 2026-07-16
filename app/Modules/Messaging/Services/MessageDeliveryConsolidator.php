<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Support\EmailConsentRevocationLinkGenerator;
use Illuminate\Database\Eloquent\Model;

class MessageDeliveryConsolidator
{
    public function __construct(
        private readonly EmailConsentRevocationLinkGenerator $emailConsentRevocationLinkGenerator,
    ) {}

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @return array<int, MessageDeliveryIntent>
     */
    public function consolidate(array $intents, string $policyKey): array
    {
        $policyKey = $this->normalizeSegment($policyKey);
        $policy = config("messaging.delivery_consolidation.policies.{$policyKey}", []);

        if (! is_array($policy) || ! ($policy['enabled'] ?? false)) {
            return $intents;
        }

        $groups = $policy['groups'] ?? null;

        if (! is_array($groups) || $groups === []) {
            return $intents;
        }

        $replacements = [];
        $consumed = [];

        foreach ($groups as $groupKey => $group) {
            if (! is_string($groupKey) || ! is_array($group)) {
                continue;
            }

            $primary = $this->primaryIntent($intents, $group, $consumed);

            if (! $primary instanceof MessageDeliveryIntent) {
                continue;
            }

            $members = $this->memberIntents($intents, $primary, $group, $consumed);

            if ($members === []) {
                continue;
            }

            $consolidated = $this->consolidatedIntent(
                policyKey: $policyKey,
                groupKey: $this->normalizeSegment($groupKey),
                group: $group,
                primary: $primary,
                members: $members,
            );

            if (! $consolidated instanceof MessageDeliveryIntent) {
                continue;
            }

            $primaryId = spl_object_id($primary);
            $replacements[$primaryId] = $consolidated;
            $consumed[$primaryId] = true;

            foreach ($members as $member) {
                $consumed[spl_object_id($member)] = true;
            }
        }

        $resolved = [];

        foreach ($intents as $intent) {
            $id = spl_object_id($intent);

            if (isset($replacements[$id])) {
                $resolved[] = $replacements[$id];

                continue;
            }

            if (isset($consumed[$id])) {
                continue;
            }

            $resolved[] = $intent;
        }

        return $resolved;
    }

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @param array<string, mixed> $group
     * @param array<int, bool> $consumed
     */
    private function primaryIntent(array $intents, array $group, array $consumed): ?MessageDeliveryIntent
    {
        $primaryKey = $this->nullableSegment($group['primary_intent'] ?? null);
        $channel = $this->nullableSegment($group['channel'] ?? null);

        if ($primaryKey === null || $channel === null) {
            return null;
        }

        foreach ($intents as $intent) {
            if (isset($consumed[spl_object_id($intent)])) {
                continue;
            }

            if ($this->normalizeSegment($intent->key) !== $primaryKey) {
                continue;
            }

            if ($intent->channel() !== $channel) {
                continue;
            }

            return $intent;
        }

        return null;
    }

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @param array<string, mixed> $group
     * @param array<int, bool> $consumed
     * @return array<int, MessageDeliveryIntent>
     */
    private function memberIntents(
        array $intents,
        MessageDeliveryIntent $primary,
        array $group,
        array $consumed,
    ): array {
        $memberKeys = array_values(array_unique(array_filter(array_map(
            fn (mixed $key): ?string => $this->nullableSegment($key),
            is_array($group['member_intents'] ?? null) ? $group['member_intents'] : [],
        ))));

        if ($memberKeys === []) {
            return [];
        }

        $members = [];

        foreach ($intents as $intent) {
            $id = spl_object_id($intent);

            if ($intent === $primary || isset($consumed[$id])) {
                continue;
            }

            if (! in_array($this->normalizeSegment($intent->key), $memberKeys, true)) {
                continue;
            }

            if (! $this->sameModel($primary->recipient, $intent->recipient)) {
                continue;
            }

            if (! $this->sameNullableModel($primary->context, $intent->context)) {
                continue;
            }

            $members[] = $intent;
        }

        return $members;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<int, MessageDeliveryIntent> $members
     */
    private function consolidatedIntent(
        string $policyKey,
        string $groupKey,
        array $group,
        MessageDeliveryIntent $primary,
        array $members,
    ): ?MessageDeliveryIntent {
        $template = $group['template'] ?? null;

        if (! is_array($template)) {
            return null;
        }

        $template = $this->replaceSystemMarkersRecursive($template);
        $dispatchKeys = $template['dispatch_keys'] ?? null;

        if ($dispatchKeys === null && is_string($template['dispatch_key'] ?? null)) {
            $dispatchKeys = [$template['dispatch_key']];
        }

        if (! is_array($dispatchKeys) || $dispatchKeys === []) {
            return null;
        }

        $template['dispatch_keys'] = $dispatchKeys;
        unset($template['dispatch_key']);

        $included = [$primary, ...$members];
        $includedIntentKeys = array_values(array_unique(array_map(
            fn (MessageDeliveryIntent $intent): string => $this->normalizeSegment($intent->key),
            $included,
        )));
        $consentIds = $this->consentIds($included);

        $payload = $primary->payload;
        $tokens = is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [];
        $fragments = is_array($group['fragments'] ?? null) ? $group['fragments'] : [];

        foreach ($fragments as $token => $fragment) {
            if (! is_string($token) || trim($token) === '' || ! is_array($fragment)) {
                continue;
            }

            $intentKey = $this->nullableSegment($fragment['intent_key'] ?? null);
            $text = $this->nullableString($fragment['text'] ?? null);

            $tokens[$token] = $intentKey !== null
                && in_array($intentKey, $includedIntentKeys, true)
                && $text !== null
                    ? $this->replaceSystemMarkers($text)
                    : '';
        }

        $payload['tokens'] = $tokens;

        if (
            ($group['include_marketing_unsubscribe'] ?? false)
            && in_array('consent.marketing.email.acknowledgement', $includedIntentKeys, true)
            && $primary->recipient instanceof Contact
        ) {
            $payload['unsubscribe_url'] = $this->emailConsentRevocationLinkGenerator
                ->marketingUnsubscribeUrl($primary->recipient);
        }

        $meta = array_replace_recursive(
            $primary->meta,
            [
                'delivery_consolidation' => [
                    'policy' => $policyKey,
                    'group' => $groupKey,
                    'intent_keys' => $includedIntentKeys,
                    'consent_ids' => $consentIds,
                    'primary_intent_key' => $this->normalizeSegment($primary->key),
                    'template_key' => $template['key'] ?? null,
                ],
            ],
        );

        $template['meta'] = array_replace_recursive(
            is_array($template['meta'] ?? null) ? $template['meta'] : [],
            [
                'delivery_consolidation_template' => [
                    'policy' => $policyKey,
                    'group' => $groupKey,
                    'intent_keys' => $includedIntentKeys,
                ],
            ],
        );

        return new MessageDeliveryIntent(
            key: "delivery_consolidation.{$policyKey}.{$groupKey}",
            recipient: $primary->recipient,
            definition: $template,
            payload: $payload,
            context: $primary->context,
            triggeredAt: $primary->triggeredAt,
            anchor: $primary->anchor,
            sendAt: $primary->sendAt,
            behaviorOwner: $primary->behaviorOwner,
            behavior: $primary->behavior,
            occurrenceKey: implode(':', array_filter([
                $primary->occurrenceKey,
                'delivery_consolidation',
                $groupKey,
            ], fn (mixed $value): bool => is_string($value) && trim($value) !== '')),
            meta: $meta,
        );
    }

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @return array<int, int>
     */
    private function consentIds(array $intents): array
    {
        $ids = [];

        foreach ($intents as $intent) {
            $values = data_get($intent->meta, 'delivery_intent.consent_ids', []);

            if (! is_array($values)) {
                $values = [];
            }

            $single = data_get($intent->meta, 'consent.message_consent_id');

            if (is_numeric($single)) {
                $values[] = (int) $single;
            }

            foreach ($values as $value) {
                if (is_numeric($value)) {
                    $ids[] = (int) $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function sameModel(Model $left, Model $right): bool
    {
        return $left->getMorphClass() === $right->getMorphClass()
            && (string) $left->getKey() === (string) $right->getKey();
    }

    private function sameNullableModel(?Model $left, ?Model $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $this->sameModel($left, $right);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function replaceSystemMarkersRecursive(array $values): array
    {
        array_walk_recursive($values, function (&$value): void {
            if (is_string($value)) {
                $value = $this->replaceSystemMarkers($value);
            }
        });

        return $values;
    }

    private function replaceSystemMarkers(string $value): string
    {
        return strtr($value, [
            ':client_name' => $this->clientName(),
        ]);
    }

    private function clientName(): string
    {
        $name = config('client.name', config('app.name', ''));

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : 'this organization';
    }

    private function nullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
