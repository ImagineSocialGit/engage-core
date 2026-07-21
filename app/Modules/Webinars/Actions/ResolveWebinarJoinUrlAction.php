<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ResolveWebinarJoinUrlAction
{
    private const MAX_REPLACEMENT_DEPTH = 50;

    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessagesAction,
    ) {}

    /**
     * Resolve the current destination without recording a trusted interaction.
     *
     * This method is safe for public GET and HEAD requests, including scanners,
     * previews, and prefetchers.
     */
    public function handle(WebinarRegistration $registration): ?string
    {
        $chain = $this->resolveChain((int) $registration->getKey());

        if (! $this->chainIsJoinable($chain)) {
            return null;
        }

        return $this->destinationFor($chain['canonical']);
    }

    public function canonicalRegistration(
        WebinarRegistration $registration,
    ): WebinarRegistration {
        return $this->resolveChain((int) $registration->getKey())['canonical'];
    }

    public function requiresReplacementRecovery(
        WebinarRegistration $registration,
    ): bool {
        $chain = $this->resolveChain((int) $registration->getKey());

        if ($chain['cycle_detected'] || $chain['unresolved_replacement']) {
            return true;
        }

        return ! $this->replacementFinalizationReady($chain['canonical']);
    }

    /**
     * Resolve the destination and atomically record an explicitly trusted join
     * interaction before suppressing eligible pending live reminders.
     */
    public function execute(WebinarRegistration $registration): ?string
    {
        return DB::transaction(function () use ($registration): ?string {
            $chain = $this->resolveChain(
                registrationId: (int) $registration->getKey(),
                lock: true,
            );

            if (! $this->chainIsJoinable($chain)) {
                return null;
            }

            $destination = $this->destinationFor($chain['canonical']);

            if (blank($destination)) {
                return null;
            }

            $this->markTrustedJoinInteraction(
                registration: $chain['canonical'],
                resolvedFromRegistrationId: (int) $chain['original']->getKey(),
            );
            $this->skipJoinClickedMessages($chain['canonical']);

            return $destination;
        });
    }

    /**
     * @return array{
     *     original: WebinarRegistration,
     *     canonical: WebinarRegistration,
     *     unresolved_replacement: bool,
     *     cancelled: bool,
     *     cycle_detected: bool
     * }
     */
    private function resolveChain(
        int $registrationId,
        bool $lock = false,
    ): array {
        $original = $this->registrationQuery($lock)
            ->findOrFail($registrationId);
        $current = $original;
        $visited = [];
        $cancelled = false;
        $cycleDetected = false;
        $unresolvedReplacement = false;
        $terminated = false;

        for ($depth = 0; $depth < self::MAX_REPLACEMENT_DEPTH; $depth++) {
            $currentId = (int) $current->getKey();

            if (isset($visited[$currentId])) {
                $cycleDetected = true;
                $terminated = true;
                break;
            }

            $visited[$currentId] = true;

            if (
                $current->status === 'cancelled'
                || $current->cancelled_at !== null
            ) {
                $cancelled = true;
                $terminated = true;
                break;
            }

            $replacement = $this->registrationQuery($lock)
                ->where('replacement_of_registration_id', $currentId)
                ->first();

            if ($replacement instanceof WebinarRegistration) {
                $current = $replacement;

                continue;
            }

            $unresolvedReplacement = Webinar::query()
                ->where('replacement_of_webinar_id', $current->webinar_id)
                ->exists();
            $terminated = true;

            break;
        }

        if (! $terminated) {
            $cycleDetected = true;
        }

        return [
            'original' => $original,
            'canonical' => $current,
            'unresolved_replacement' => $unresolvedReplacement,
            'cancelled' => $cancelled,
            'cycle_detected' => $cycleDetected,
        ];
    }

    /** @return Builder<WebinarRegistration> */
    private function registrationQuery(bool $lock): Builder
    {
        $query = WebinarRegistration::query()->with([
            'webinar',
            'webinar.webinarSeries',
        ]);

        return $lock ? $query->lockForUpdate() : $query;
    }

    /** @param array<string, mixed> $chain */
    private function chainIsJoinable(array $chain): bool
    {
        if (
            $chain['cancelled']
            || $chain['cycle_detected']
            || $chain['unresolved_replacement']
        ) {
            return false;
        }

        return $this->replacementFinalizationReady($chain['canonical']);
    }

    private function replacementFinalizationReady(
        WebinarRegistration $registration,
    ): bool {
        $state = data_get(
            $registration->meta,
            WebinarRegistrationFinalizationResult::META_KEY,
        );

        if (! is_array($state) || ($state['mode'] ?? null) !== 'replacement_reprovisioning') {
            return true;
        }

        return ($state['status'] ?? null) === 'completed';
    }

    private function destinationFor(
        WebinarRegistration $registration,
    ): ?string {
        $destination = data_get($registration->meta, 'provider.join_url')
            ?: $registration->webinar?->join_url;

        return filled($destination)
            ? trim((string) $destination)
            : null;
    }

    private function markTrustedJoinInteraction(
        WebinarRegistration $registration,
        int $resolvedFromRegistrationId,
    ): void {
        $meta = is_array($registration->meta)
            ? $registration->meta
            : [];
        $recordedAt = now()->toISOString();
        $existingCount = (int) ($meta['join_click_count'] ?? 0);
        $interaction = is_array($meta['join_interaction'] ?? null)
            ? $meta['join_interaction']
            : [];
        $firstConfirmedAt = $interaction['first_confirmed_at']
            ?? $meta['join_clicked_at']
            ?? $recordedAt;

        $meta['join_clicked_at'] = $recordedAt;
        $meta['join_click_count'] = $existingCount + 1;
        $meta['join_interaction'] = array_replace($interaction, [
            'source' => 'public_signed_post',
            'first_confirmed_at' => $firstConfirmedAt,
            'last_confirmed_at' => $recordedAt,
            'confirmed_count' => $existingCount + 1,
            'resolved_from_registration_id' => $resolvedFromRegistrationId,
            'canonical_registration_id' => (int) $registration->getKey(),
        ]);

        $registration->forceFill([
            'meta' => $meta,
        ])->save();
    }

    private function skipJoinClickedMessages(
        WebinarRegistration $registration,
    ): void {
        $this->skipScheduledMessagesAction->forContextMetaValue(
            context: $registration,
            key: 'skip_when_join_clicked',
            value: true,
            reason: 'Registrant confirmed the join action before the live reminder.',
        );
    }
}