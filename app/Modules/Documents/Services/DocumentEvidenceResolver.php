<?php

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentUpload;
use App\Support\ModuleIntegrations\Documents\Contracts\DocumentEvidenceSource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class DocumentEvidenceResolver implements DocumentEvidenceSource
{
    public function latestApproved(
        Model $subject,
        string $requirementKey,
        ?CarbonInterface $validThrough = null,
        bool $requireExpiration = false,
    ): ?DocumentUpload {
        if (! $subject->exists || $subject->getKey() === null) {
            throw new InvalidArgumentException(
                'Document evidence resolution requires a persisted subject.',
            );
        }

        $requirementKey = trim($requirementKey);

        if ($requirementKey === '') {
            throw new InvalidArgumentException(
                'Document evidence resolution requires a document requirement key.',
            );
        }

        $query = DocumentUpload::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('review_status', DocumentUpload::REVIEW_STATUS_APPROVED)
            ->whereNotIn('status', [
                DocumentUpload::STATUS_REJECTED,
                DocumentUpload::STATUS_SUPERSEDED,
                DocumentUpload::STATUS_ARCHIVED,
                DocumentUpload::STATUS_DELETED,
            ])
            ->whereHas(
                'requirementDefinition',
                fn (Builder $definition): Builder => $definition
                    ->where('key', $requirementKey)
                    ->where('status', DocumentRequirementDefinition::STATUS_ACTIVE),
            );

        if ($requireExpiration) {
            $query->whereNotNull('expires_at');
        }

        if ($validThrough instanceof CarbonInterface) {
            $validThrough = CarbonImmutable::instance($validThrough)->utc();

            $query->where(function (Builder $validity) use (
                $validThrough,
                $requireExpiration,
            ): void {
                if (! $requireExpiration) {
                    $validity->whereNull('expires_at');
                }

                $validity->orWhere('expires_at', '>=', $validThrough);
            });
        }

        return $query
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();
    }

    public function satisfies(
        Model $subject,
        string $requirementKey,
        ?CarbonInterface $validThrough = null,
        bool $requireExpiration = false,
    ): bool {
        return $this->latestApproved(
            subject: $subject,
            requirementKey: $requirementKey,
            validThrough: $validThrough,
            requireExpiration: $requireExpiration,
        ) instanceof DocumentUpload;
    }
}