<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentReviewEvent;
use App\Modules\Documents\Models\DocumentUpload;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReviewDocumentUploadAction
{
    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    public function handle(
        DocumentUpload $upload,
        string $decision,
        ?Model $actor = null,
        ?string $reason = null,
        ?string $notes = null,
        ?CarbonInterface $occurredAt = null,
    ): DocumentUpload {
        if (! $upload->exists || $upload->getKey() === null) {
            throw new InvalidArgumentException(
                'Document review requires a persisted upload.',
            );
        }

        if ($actor !== null && (! $actor->exists || $actor->getKey() === null)) {
            throw new InvalidArgumentException(
                'Document review actor must be persisted when provided.',
            );
        }

        if (! in_array($decision, [
            self::DECISION_APPROVED,
            self::DECISION_REJECTED,
        ], true)) {
            throw new InvalidArgumentException(
                'Document review decision must be approved or rejected.',
            );
        }

        $occurredAt = $occurredAt instanceof CarbonInterface
            ? CarbonImmutable::instance($occurredAt)->utc()
            : CarbonImmutable::now('UTC');

        return DB::transaction(function () use (
            $upload,
            $decision,
            $actor,
            $reason,
            $notes,
            $occurredAt,
        ): DocumentUpload {
            $locked = DocumentUpload::query()
                ->whereKey($upload->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [
                DocumentUpload::STATUS_SUPERSEDED,
                DocumentUpload::STATUS_ARCHIVED,
                DocumentUpload::STATUS_DELETED,
            ], true)) {
                throw new DomainException(
                    'This document upload is no longer reviewable.',
                );
            }

            if ($decision === self::DECISION_APPROVED
                && $locked->review_status === DocumentUpload::REVIEW_STATUS_APPROVED
                && $locked->status === DocumentUpload::STATUS_APPROVED
            ) {
                return $locked->refresh();
            }

            $request = $locked->document_request_id !== null
                ? DocumentRequest::query()
                    ->whereKey($locked->document_request_id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($request instanceof DocumentRequest
                && in_array($request->status, [
                    DocumentRequest::STATUS_WAIVED,
                    DocumentRequest::STATUS_EXPIRED,
                    DocumentRequest::STATUS_CANCELLED,
                ], true)
            ) {
                throw new DomainException(
                    'The document request is no longer reviewable.',
                );
            }

            $fromStatus = $locked->status;

            if ($decision === self::DECISION_APPROVED) {
                $locked->forceFill([
                    'status' => DocumentUpload::STATUS_APPROVED,
                    'review_status' => DocumentUpload::REVIEW_STATUS_APPROVED,
                    'reviewed_at' => $occurredAt,
                    'approved_at' => $occurredAt,
                    'rejected_at' => null,
                ])->save();

                if ($request instanceof DocumentRequest) {
                    $request->forceFill([
                        'status' => DocumentRequest::STATUS_SATISFIED,
                        'satisfied_at' => $occurredAt,
                    ])->save();
                }

                $event = DocumentReviewEvent::EVENT_APPROVED;
            } else {
                $locked->forceFill([
                    'status' => DocumentUpload::STATUS_REJECTED,
                    'review_status' => DocumentUpload::REVIEW_STATUS_REJECTED,
                    'reviewed_at' => $occurredAt,
                    'approved_at' => null,
                    'rejected_at' => $occurredAt,
                ])->save();

                if ($request instanceof DocumentRequest) {
                    $request->forceFill([
                        'status' => DocumentRequest::STATUS_REPLACEMENT_REQUESTED,
                        'satisfied_at' => null,
                    ])->save();
                }

                $event = DocumentReviewEvent::EVENT_REJECTED;
            }

            DocumentReviewEvent::query()->create([
                'document_request_id' => $request?->getKey(),
                'document_upload_id' => $locked->getKey(),
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'event' => $event,
                'from_status' => $fromStatus,
                'to_status' => $locked->status,
                'reason' => $this->nullableString($reason),
                'notes' => $this->nullableString($notes),
                'occurred_at' => $occurredAt,
            ]);

            return $locked->refresh();
        });
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }
}