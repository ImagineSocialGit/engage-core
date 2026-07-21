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
        private readonly MessageDeliveryPrimarySelector $primarySelector,
    ) {}

    public function coversIntent(
        string $policyKey,
        string $primaryIntentKey,
        string $memberIntentKey,
        string $channel,
    ): bool {
        $policy = config(
            'messaging.delivery_consolidation.policies.'.$this->normalizeSegment($policyKey),
            [],
        );

        if (! is_array($policy) || ! ($policy['enabled'] ?? false)) {
            return false;
        }

        $groups = is_array($policy['groups'] ?? null) ? $policy['groups'] : [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            if ($this->nullableSegment($group['channel'] ?? null) !== $this->normalizeSegment($channel)) {
                continue;
            }

            if ($this->nullableSegment($group['primary_intent'] ?? null) !== $this->normalizeSegment($primaryIntentKey)) {
                continue;
            }

            $memberIntents = array_values(array_filter(array_map(
                fn (mixed $intent): ?string => $this->nullableSegment($intent),
                is_array($group['member_intents'] ?? null) ? $group['member_intents'] : [],
            )));

            if (! in_array($this->normalizeSegment($memberIntentKey), $memberIntents, true)) {
                continue;
            }

            $fragments = is_array($group['fragments'] ?? null) ? $group['fragments'] : [];
            $fragmentCoversIntent = false;

            foreach ($fragments as $fragment) {
                if (
                    is_array($fragment)
                    && $this->nullableSegment($fragment['intent_key'] ?? null) === $this->normalizeSegment($memberIntentKey)
                ) {
                    $fragmentCoversIntent = true;
                    break;
                }
            }

            if (! $fragmentCoversIntent) {
                continue;
            }

            $placement = is_array($group['placement'] ?? null) ? $group['placement'] : [];
            $payloadKey = $this->nullableString($placement['payload_key'] ?? null);
            $position = $this->nullableSegment($placement['position'] ?? null) ?? 'append';

            if ($payloadKey === null || ! in_array($position, ['append', 'prepend'], true)) {
                continue;
            }

            return true;
        }

        return false;
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

            $candidateMembers = $this->candidateMemberIntents(
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

            $templateSource = 'primary_intent';

            if (! $primary instanceof MessageDeliveryIntent) {
                $primary = $this->standalonePrimaryIntent(
                    members: $candidateMembers,
                    group: $group,
                );
                $templateSource = 'standalone_intent';
            }

            if (! $primary instanceof MessageDeliveryIntent) {
                continue;
            }

            $members = $this->memberIntents(
                intents: $intents,
                primary: $primary,
                group: $group,
                consumed: $consumed,
            );

            if ($members === []) {
                continue;
            }

            $consolidated = $this->consolidatedIntent(
                policyKey: $policyKey,
                groupKey: $this->normalizeSegment($groupKey),
                group: $group,
                primary: $primary,
                members: $members,
                templateSource: $templateSource,
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
     * @return array<int, MessageDeliveryIntent>
     */
    private function candidateMemberIntents(
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
                && in_array(
                    $this->normalizeSegment($intent->key),
                    $memberKeys,
                    true,
                ),
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
        $preferredKeys = array_values(array_unique(array_filter(array_map(
            fn (mixed $key): ?string => $this->nullableSegment($key),
            is_array($group['standalone_primary_intents'] ?? null)
                ? $group['standalone_primary_intents']
                : [],
        ))));

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
    private function memberIntents(
        array $intents,
        MessageDeliveryIntent $primary,
        array $group,
        array $consumed,
    ): array {
        $memberKeys = $this->memberIntentKeys($group);

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

            if ($intent->channel() !== $primary->channel()) {
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
        string $templateSource,
    ): ?MessageDeliveryIntent {
        $definition = $primary->definition;
        $dispatchKeys = $definition['dispatch_keys'] ?? null;

        if ($dispatchKeys === null && is_string($definition['dispatch_key'] ?? null)) {
            $dispatchKeys = [$definition['dispatch_key']];
        }

        if (! is_array($dispatchKeys) || $dispatchKeys === []) {
            return null;
        }

        $definition['dispatch_keys'] = $dispatchKeys;
        unset($definition['dispatch_key']);

        $included = [$primary, ...$members];
        $includedIntentKeys = array_values(array_unique(array_map(
            fn (MessageDeliveryIntent $intent): string => $this->normalizeSegment($intent->key),
            $included,
        )));
        $memberIntentKeys = array_values(array_unique(array_map(
            fn (MessageDeliveryIntent $intent): string => $this->normalizeSegment($intent->key),
            $members,
        )));

        $resolvedFragments = $this->resolvedFragments($group, $memberIntentKeys);

        if ($resolvedFragments === null) {
            return null;
        }

        $placement = $this->applyFragmentPlacement(
            definition: $definition,
            group: $group,
            fragmentTokens: array_keys($resolvedFragments),
        );

        if ($placement === null) {
            return null;
        }

        $definition = $placement['definition'];
        $payload = $primary->payload;
        $tokens = is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [];

        foreach ($resolvedFragments as $token => $text) {
            $tokens[$token] = $text;
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

        $consentIds = $this->consentIds($included);
        $definitionKey = $this->definitionKey($definition);

        $meta = array_replace_recursive(
            $primary->meta,
            [
                'delivery_consolidation' => [
                    'policy' => $policyKey,
                    'group' => $groupKey,
                    'intent_keys' => $includedIntentKeys,
                    'consent_ids' => $consentIds,
                    'primary_intent_key' => $this->normalizeSegment($primary->key),
                    'template_key' => $definitionKey,
                    'template_source' => $templateSource,
                    'payload_key' => $placement['payload_key'],
                    'position' => $placement['position'],
                    'separator' => $placement['separator'],
                    'fragment_tokens' => array_keys($resolvedFragments),
                ],
            ],
        );

        return new MessageDeliveryIntent(
            key: "delivery_consolidation.{$policyKey}.{$groupKey}",
            recipient: $primary->recipient,
            definition: $definition,
            payload: $payload,
            context: $primary->context,
            triggeredAt: $primary->triggeredAt,
            anchor: $primary->anchor,
            sendAt: $primary->sendAt,
            behaviorOwner: $primary->behaviorOwner,
            behavior: $primary->behavior,
            occurrenceKey: $templateSource === 'primary_intent'
                ? $primary->occurrenceKey
                : implode(':', array_filter([
                    $primary->occurrenceKey,
                    'delivery_consolidation',
                    $groupKey,
                ], fn (mixed $value): bool =>
                    is_string($value) && trim($value) !== ''
                )),
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $group
     * @param array<int, string> $memberIntentKeys
     * @return array<string, string>|null
     */
    private function resolvedFragments(array $group, array $memberIntentKeys): ?array
    {
        $fragments = is_array($group['fragments'] ?? null) ? $group['fragments'] : [];

        if ($fragments === [] || $memberIntentKeys === []) {
            return null;
        }

        $resolved = [];
        $coveredIntentKeys = [];

        foreach ($fragments as $token => $fragment) {
            if (! is_string($token) || trim($token) === '' || ! is_array($fragment)) {
                continue;
            }

            $intentKey = $this->nullableSegment($fragment['intent_key'] ?? null);
            $text = $this->nullableString($fragment['text'] ?? null);

            if (
                $intentKey === null
                || $text === null
                || ! in_array($intentKey, $memberIntentKeys, true)
            ) {
                continue;
            }

            $resolved[trim($token)] = $this->replaceSystemMarkers($text);
            $coveredIntentKeys[] = $intentKey;
        }

        $coveredIntentKeys = array_values(array_unique($coveredIntentKeys));

        if (array_diff($memberIntentKeys, $coveredIntentKeys) !== []) {
            return null;
        }

        return $resolved !== [] ? $resolved : null;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $group
     * @param array<int, string> $fragmentTokens
     * @return array{definition: array<string, mixed>, payload_key: string, position: string, separator: string}|null
     */
    private function applyFragmentPlacement(
        array $definition,
        array $group,
        array $fragmentTokens,
    ): ?array {
        $placement = is_array($group['placement'] ?? null) ? $group['placement'] : [];
        $payloadKey = $this->nullableString($placement['payload_key'] ?? null);
        $position = $this->nullableSegment($placement['position'] ?? null) ?? 'append';
        $separator = is_string($placement['separator'] ?? null)
            ? $placement['separator']
            : "\n\n";

        if ($payloadKey === null || ! in_array($position, ['append', 'prepend'], true)) {
            return null;
        }

        $payloadPath = 'payload.'.$payloadKey;
        $currentValue = data_get($definition, $payloadPath);

        if (! is_string($currentValue) || trim($currentValue) === '') {
            return null;
        }

        $placeholders = array_map(
            fn (string $token): string => '{'.$token.'}',
            $fragmentTokens,
        );
        $fragmentBlock = implode($separator, $placeholders);

        if ($fragmentBlock === '') {
            return null;
        }

        data_set(
            $definition,
            $payloadPath,
            $position === 'prepend'
                ? $fragmentBlock.$separator.$currentValue
                : $currentValue.$separator.$fragmentBlock,
        );

        return [
            'definition' => $definition,
            'payload_key' => $payloadKey,
            'position' => $position,
            'separator' => $separator,
        ];
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

    /**
     * @param array<string, mixed> $definition
     */
    private function definitionKey(array $definition): ?string
    {
        foreach ([
            $definition['definition_key'] ?? null,
            $definition['key'] ?? null,
            data_get($definition, 'meta.message_template_assignment.definition_key'),
            data_get($definition, 'meta.seed.definition_key'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
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