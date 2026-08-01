<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\Delivery\MessageDeliveryComponent;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Models\ScheduledMessageComponent;
use Illuminate\Database\Eloquent\Model;

class MessageDeliveryConsolidator
{
    public function __construct(
        private readonly MessageDeliveryPrimarySelector $primarySelector,
    ) {}

    public function coversIntent(
        string $policyKey,
        string $primaryIntentKey,
        string $memberIntentKey,
        string $channel,
    ): bool {
        return $this->groupForCarrier(
            policyKey: $policyKey,
            primaryIntentKey: $primaryIntentKey,
            channel: $channel,
            memberIntentKey: $memberIntentKey,
        ) !== null;
    }

    /**
     * @param array<int, MessageDeliveryIntent> $memberIntents
     * @return array<int, MessageDeliveryComponent>
     */
    public function componentsForCarrier(
        array $memberIntents,
        string $policyKey,
        string $primaryIntentKey,
        string $channel,
    ): array {
        $group = $this->groupForCarrier(
            policyKey: $policyKey,
            primaryIntentKey: $primaryIntentKey,
            channel: $channel,
        );

        if ($group === null) {
            return [];
        }

        return $this->componentsFromMembers(
            members: array_values(array_filter(
                $memberIntents,
                fn (mixed $intent): bool =>
                    $intent instanceof MessageDeliveryIntent
                    && $intent->channel() === $this->normalizeSegment($channel)
                    && in_array(
                        $this->normalizeSegment($intent->key),
                        $this->memberIntentKeys($group),
                        true,
                    ),
            )),
            group: $group,
        );
    }

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @return array<int, MessageDeliveryIntent>
     */
    public function consolidate(array $intents, string $policyKey): array
    {
        $policyKey = $this->normalizeSegment($policyKey);
        $policy = config(
            "messaging.delivery_consolidation.policies.{$policyKey}",
            [],
        );

        if (! is_array($policy) || ! ($policy['enabled'] ?? false)) {
            return $intents;
        }

        $groups = is_array($policy['groups'] ?? null)
            ? $policy['groups']
            : [];
        $replacements = [];
        $consumed = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $candidateMembers = $this->candidateMembers(
                intents: $intents,
                group: $group,
                consumed: $consumed,
            );

            if ($candidateMembers === []) {
                continue;
            }

            $primary = $this->primarySelector->select(
                intents: $intents,
                group: $group,
                consumed: $consumed,
            );

            if (! $primary instanceof MessageDeliveryIntent) {
                $primary = $this->standalonePrimaryIntent(
                    members: $candidateMembers,
                    group: $group,
                );
            }

            if (! $primary instanceof MessageDeliveryIntent) {
                continue;
            }

            $members = $this->membersForPrimary(
                intents: $intents,
                primary: $primary,
                group: $group,
                consumed: $consumed,
            );
            $components = $this->componentsFromMembers($members, $group);

            if ($components === []) {
                continue;
            }

            $primaryId = spl_object_id($primary);
            $replacements[$primaryId] = $primary->withComponents([
                ...$primary->components,
                ...$components,
            ]);
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

            if (! isset($consumed[$id])) {
                $resolved[] = $intent;
            }
        }

        return $resolved;
    }

    /**
     * @param array<int, MessageDeliveryIntent> $members
     * @param array<string, mixed> $group
     * @return array<int, MessageDeliveryComponent>
     */
    private function componentsFromMembers(array $members, array $group): array
    {
        $placementKey = $this->nullableSegment($group['placement_key'] ?? null);

        if ($placementKey === null) {
            return [];
        }

        $components = [];
        $sortOrder = 100;

        foreach ($members as $member) {
            $versionId = $member->definition['message_template_version_id'] ?? null;
            $consentId = data_get($member->meta, 'consent.message_consent_id');

            if (! is_numeric($versionId) || ! is_numeric($consentId)) {
                continue;
            }

            $components[] = new MessageDeliveryComponent(
                channel: $member->channel(),
                messageTemplateVersionId: (int) $versionId,
                role: ScheduledMessageComponent::ROLE_CONSENT_ACKNOWLEDGEMENT,
                intentKey: $this->normalizeSegment($member->key),
                messageConsentId: (int) $consentId,
                sortOrder: $sortOrder,
                placementKey: $placementKey,
                standaloneIntent: $member,
            );
            $sortOrder += 10;
        }

        return $components;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function groupForCarrier(
        string $policyKey,
        string $primaryIntentKey,
        string $channel,
        ?string $memberIntentKey = null,
    ): ?array {
        $policy = config(
            'messaging.delivery_consolidation.policies.'
                .$this->normalizeSegment($policyKey),
            [],
        );

        if (! is_array($policy) || ! ($policy['enabled'] ?? false)) {
            return null;
        }

        foreach (is_array($policy['groups'] ?? null) ? $policy['groups'] : [] as $group) {
            if (! is_array($group)
                || $this->nullableSegment($group['channel'] ?? null) !== $this->normalizeSegment($channel)
                || $this->nullableSegment($group['primary_intent'] ?? null) !== $this->normalizeSegment($primaryIntentKey)
                || $this->nullableSegment($group['placement_key'] ?? null) === null
            ) {
                continue;
            }

            if ($memberIntentKey !== null
                && ! in_array(
                    $this->normalizeSegment($memberIntentKey),
                    $this->memberIntentKeys($group),
                    true,
                )
            ) {
                continue;
            }

            return $group;
        }

        return null;
    }

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @param array<string, mixed> $group
     * @param array<int, bool> $consumed
     * @return array<int, MessageDeliveryIntent>
     */
    private function candidateMembers(
        array $intents,
        array $group,
        array $consumed,
    ): array {
        $memberKeys = $this->memberIntentKeys($group);
        $channel = $this->nullableSegment($group['channel'] ?? null);

        if ($memberKeys === [] || $channel === null) {
            return [];
        }

        return array_values(array_filter(
            $intents,
            fn (MessageDeliveryIntent $intent): bool =>
                ! isset($consumed[spl_object_id($intent)])
                && $intent->channel() === $channel
                && in_array($this->normalizeSegment($intent->key), $memberKeys, true),
        ));
    }

    /**
     * @param array<int, MessageDeliveryIntent> $members
     * @param array<string, mixed> $group
     */
    private function standalonePrimaryIntent(
        array $members,
        array $group,
    ): ?MessageDeliveryIntent {
        $preferredKeys = array_values(array_filter(array_map(
            fn (mixed $key): ?string => $this->nullableSegment($key),
            is_array($group['standalone_primary_intents'] ?? null)
                ? $group['standalone_primary_intents']
                : [],
        )));

        foreach ($preferredKeys as $preferredKey) {
            foreach ($members as $member) {
                if ($this->normalizeSegment($member->key) === $preferredKey) {
                    return $member;
                }
            }
        }

        return $members[0] ?? null;
    }

    /**
     * @param array<int, MessageDeliveryIntent> $intents
     * @param array<string, mixed> $group
     * @param array<int, bool> $consumed
     * @return array<int, MessageDeliveryIntent>
     */
    private function membersForPrimary(
        array $intents,
        MessageDeliveryIntent $primary,
        array $group,
        array $consumed,
    ): array {
        $memberKeys = $this->memberIntentKeys($group);

        return array_values(array_filter(
            $intents,
            fn (MessageDeliveryIntent $intent): bool =>
                $intent !== $primary
                && ! isset($consumed[spl_object_id($intent)])
                && $intent->channel() === $primary->channel()
                && in_array($this->normalizeSegment($intent->key), $memberKeys, true)
                && $this->sameModel($primary->recipient, $intent->recipient)
                && $this->sameNullableModel($primary->context, $intent->context),
        ));
    }

    /**
     * @param array<string, mixed> $group
     * @return array<int, string>
     */
    private function memberIntentKeys(array $group): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $key): ?string => $this->nullableSegment($key),
            is_array($group['member_intents'] ?? null)
                ? $group['member_intents']
                : [],
        ))));
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

    private function nullableSegment(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? $this->normalizeSegment($value)
            : null;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}