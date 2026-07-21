<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Facades\DB;
use LogicException;

class ReplaceWebinarOccurrenceAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessages,
        private readonly QueueWebinarRegistrationFinalizationAction $queueFinalization,
    ) {}

    /**
     * @return array{
     *     source_webinar_id: int,
     *     replacement_webinar_id: int,
     *     eligible_registrations: int,
     *     created_registrations: int,
     *     adopted_registrations: int,
     *     skipped_source_messages: int,
     *     replacement_registration_ids: array<int, int>,
     *     queue_statuses: array<int, string>
     * }
     */
    public function handle(
        Webinar $source,
        Webinar $replacement,
    ): array {
        $prepared = DB::transaction(function () use ($source, $replacement): array {
            $occurrences = Webinar::query()
                ->whereKey([
                    (int) $source->getKey(),
                    (int) $replacement->getKey(),
                ])
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Webinar $webinar): int => (int) $webinar->getKey());

            $lockedSource = $occurrences->get((int) $source->getKey());
            $lockedReplacement = $occurrences->get((int) $replacement->getKey());

            if (! $lockedSource instanceof Webinar || ! $lockedReplacement instanceof Webinar) {
                throw new LogicException(
                    'Both Webinar occurrences must be persisted before one can replace the other.',
                );
            }

            $this->validateOccurrenceReplacement(
                source: $lockedSource,
                replacement: $lockedReplacement,
            );

            $existingReplacement = Webinar::query()
                ->where('replacement_of_webinar_id', $lockedSource->getKey())
                ->lockForUpdate()
                ->first();

            if (
                $existingReplacement instanceof Webinar
                && ! $existingReplacement->is($lockedReplacement)
            ) {
                throw new LogicException(sprintf(
                    'Webinar occurrence [%d] is already replaced by occurrence [%d].',
                    $lockedSource->getKey(),
                    $existingReplacement->getKey(),
                ));
            }

            if (
                $lockedReplacement->replacement_of_webinar_id !== null
                && (int) $lockedReplacement->replacement_of_webinar_id !== (int) $lockedSource->getKey()
            ) {
                throw new LogicException(sprintf(
                    'Webinar occurrence [%d] already replaces a different occurrence.',
                    $lockedReplacement->getKey(),
                ));
            }

            if ((int) $lockedReplacement->replacement_of_webinar_id !== (int) $lockedSource->getKey()) {
                $lockedReplacement->forceFill([
                    'replacement_of_webinar_id' => $lockedSource->getKey(),
                ])->save();
            }

            $sourceRegistrations = WebinarRegistration::query()
                ->where('webinar_id', $lockedSource->getKey())
                ->whereNull('cancelled_at')
                ->where('status', '!=', 'cancelled')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $created = 0;
            $adopted = 0;
            $sourceRegistrationIds = [];
            $replacementRegistrationIds = [];

            foreach ($sourceRegistrations as $sourceRegistration) {
                $replacementRegistration = WebinarRegistration::query()
                    ->where('webinar_id', $lockedReplacement->getKey())
                    ->where('contact_id', $sourceRegistration->contact_id)
                    ->lockForUpdate()
                    ->first();

                if (! $replacementRegistration instanceof WebinarRegistration) {
                    $replacementRegistration = WebinarRegistration::query()->create([
                        'contact_id' => $sourceRegistration->contact_id,
                        'webinar_id' => $lockedReplacement->getKey(),
                        'replacement_of_registration_id' => $sourceRegistration->getKey(),
                        'webinar_slug' => $lockedReplacement->slug,
                        'status' => 'pending',
                        'source' => 'occurrence_replacement',
                        'registered_at' => now(),
                        'attended_at' => null,
                        'cancelled_at' => null,
                        'meta' => [],
                    ]);

                    $created++;
                } else {
                    if (
                        $replacementRegistration->replacement_of_registration_id !== null
                        && (int) $replacementRegistration->replacement_of_registration_id !== (int) $sourceRegistration->getKey()
                    ) {
                        throw new LogicException(sprintf(
                            'Replacement registration [%d] is already linked to a different source registration.',
                            $replacementRegistration->getKey(),
                        ));
                    }

                    if ($replacementRegistration->replacement_of_registration_id === null) {
                        $replacementRegistration->forceFill([
                            'replacement_of_registration_id' => $sourceRegistration->getKey(),
                        ])->save();
                    }

                    $adopted++;
                }

                $this->stageReplacementFinalization(
                    sourceRegistration: $sourceRegistration,
                    replacementRegistration: $replacementRegistration,
                    sourceWebinar: $lockedSource,
                    replacementWebinar: $lockedReplacement,
                );

                $sourceRegistrationIds[] = (int) $sourceRegistration->getKey();
                $replacementRegistrationIds[] = (int) $replacementRegistration->getKey();
            }

            return [
                'source_webinar_id' => (int) $lockedSource->getKey(),
                'replacement_webinar_id' => (int) $lockedReplacement->getKey(),
                'eligible_registrations' => $sourceRegistrations->count(),
                'created_registrations' => $created,
                'adopted_registrations' => $adopted,
                'source_registration_ids' => $sourceRegistrationIds,
                'replacement_registration_ids' => $replacementRegistrationIds,
            ];
        });

        $skippedMessages = 0;

        foreach ($prepared['source_registration_ids'] as $sourceRegistrationId) {
            $sourceRegistration = WebinarRegistration::query()->find($sourceRegistrationId);

            if (! $sourceRegistration instanceof WebinarRegistration) {
                continue;
            }

            $skippedMessages += $this->skipScheduledMessages->forContext(
                context: $sourceRegistration,
                reason: 'Webinar occurrence was explicitly replaced by a new provider occurrence.',
            );
        }

        $queueStatuses = [];

        foreach ($prepared['replacement_registration_ids'] as $replacementRegistrationId) {
            $queueStatuses[$replacementRegistrationId] = $this->queueFinalization
                ->handle($replacementRegistrationId)
                ->status;
        }

        return [
            'source_webinar_id' => $prepared['source_webinar_id'],
            'replacement_webinar_id' => $prepared['replacement_webinar_id'],
            'eligible_registrations' => $prepared['eligible_registrations'],
            'created_registrations' => $prepared['created_registrations'],
            'adopted_registrations' => $prepared['adopted_registrations'],
            'skipped_source_messages' => $skippedMessages,
            'replacement_registration_ids' => $prepared['replacement_registration_ids'],
            'queue_statuses' => $queueStatuses,
        ];
    }

    private function validateOccurrenceReplacement(
        Webinar $source,
        Webinar $replacement,
    ): void {
        if ($source->is($replacement)) {
            throw new LogicException('A Webinar occurrence cannot replace itself.');
        }

        if (
            $source->webinar_series_id === null
            || $replacement->webinar_series_id === null
            || (int) $source->webinar_series_id !== (int) $replacement->webinar_series_id
        ) {
            throw new LogicException(
                'A Webinar occurrence replacement must remain within the same Webinar series.',
            );
        }

        $ancestorId = $source->replacement_of_webinar_id;
        $visited = [];

        while ($ancestorId !== null) {
            $ancestorId = (int) $ancestorId;

            if ($ancestorId === (int) $replacement->getKey()) {
                throw new LogicException(
                    'The requested Webinar occurrence replacement would create a replacement cycle.',
                );
            }

            if (isset($visited[$ancestorId])) {
                throw new LogicException(
                    'The existing Webinar occurrence replacement chain contains a cycle.',
                );
            }

            $visited[$ancestorId] = true;
            $ancestorId = Webinar::query()
                ->whereKey($ancestorId)
                ->value('replacement_of_webinar_id');
        }
    }

    private function stageReplacementFinalization(
        WebinarRegistration $sourceRegistration,
        WebinarRegistration $replacementRegistration,
        Webinar $sourceWebinar,
        Webinar $replacementWebinar,
    ): void {
        $sourceMeta = is_array($sourceRegistration->meta)
            ? $sourceRegistration->meta
            : [];
        $replacementMeta = is_array($replacementRegistration->meta)
            ? $replacementRegistration->meta
            : [];
        $existingState = is_array(
            $replacementMeta[WebinarRegistrationFinalizationResult::META_KEY] ?? null,
        )
            ? $replacementMeta[WebinarRegistrationFinalizationResult::META_KEY]
            : [];
        $stagedAt = now()->toISOString();

        $replacementMeta['accepted_channels'] = is_array($sourceMeta['accepted_channels'] ?? null)
            ? $sourceMeta['accepted_channels']
            : [];
        $replacementMeta['occurrence_replacement'] = array_replace(
            is_array($replacementMeta['occurrence_replacement'] ?? null)
                ? $replacementMeta['occurrence_replacement']
                : [],
            [
                'source_webinar_id' => (int) $sourceWebinar->getKey(),
                'replacement_webinar_id' => (int) $replacementWebinar->getKey(),
                'source_registration_id' => (int) $sourceRegistration->getKey(),
                'replacement_registration_id' => (int) $replacementRegistration->getKey(),
                'staged_at' => $stagedAt,
            ],
        );

        if (($existingState['mode'] ?? null) !== 'replacement_reprovisioning') {
            $replacementMeta[WebinarRegistrationFinalizationResult::META_KEY] = [
                'status' => 'pending',
                'mode' => 'replacement_reprovisioning',
                'consent_transitions' => [],
                'attempts' => 0,
                'queue_dispatch_attempts' => 0,
                'staged_at' => $stagedAt,
                'last_state_changed_at' => $stagedAt,
                'failure_reason' => null,
                'initial_completed_at' => ($existingState['status'] ?? null) === 'completed'
                    ? ($existingState['completed_at'] ?? $stagedAt)
                    : ($existingState['initial_completed_at'] ?? null),
            ];
        }

        $replacementRegistration->forceFill([
            'replacement_of_registration_id' => $sourceRegistration->getKey(),
            'source' => $replacementRegistration->source ?: 'occurrence_replacement',
            'meta' => $replacementMeta,
        ])->save();

        $sourceMeta['occurrence_replacement'] = array_replace(
            is_array($sourceMeta['occurrence_replacement'] ?? null)
                ? $sourceMeta['occurrence_replacement']
                : [],
            [
                'source_webinar_id' => (int) $sourceWebinar->getKey(),
                'replacement_webinar_id' => (int) $replacementWebinar->getKey(),
                'source_registration_id' => (int) $sourceRegistration->getKey(),
                'replacement_registration_id' => (int) $replacementRegistration->getKey(),
                'staged_at' => $stagedAt,
            ],
        );

        $sourceFinalization = is_array(
            $sourceMeta[WebinarRegistrationFinalizationResult::META_KEY] ?? null,
        )
            ? $sourceMeta[WebinarRegistrationFinalizationResult::META_KEY]
            : [];

        $sourceMeta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
            $sourceFinalization,
            [
                'status' => 'completed',
                'completed_at' => $sourceFinalization['completed_at'] ?? $stagedAt,
                'processing_started_at' => null,
                'queued_at' => null,
                'next_retry_at' => null,
                'failure_reason' => null,
                'completion_reason' => 'occurrence_replaced',
                'replacement_registration_id' => (int) $replacementRegistration->getKey(),
                'last_state_changed_at' => $stagedAt,
            ],
        );

        $sourceRegistration->forceFill(['meta' => $sourceMeta])->save();
    }
}