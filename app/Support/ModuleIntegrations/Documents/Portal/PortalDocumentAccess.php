<?php

namespace App\Support\ModuleIntegrations\Documents\Portal;

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentUpload;
use App\Modules\Portal\Models\PortalUser;
use App\Support\ModuleIntegrations\Documents\Portal\Data\PortalDocumentSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class PortalDocumentAccess
{
    public function __construct(
        private readonly PortalDocumentSubjectRegistry $subjects,
    ) {}

    /** @return Collection<int, DocumentRequest> */
    public function requestsFor(PortalUser $user): Collection
    {
        return $this->visibleRequests($user)
            ->with(['requirementDefinition'])
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, DocumentUpload> */
    public function uploadsFor(PortalUser $user): Collection
    {
        return $this->visibleUploads($user)
            ->with(['requirementDefinition'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();
    }

    public function requestFor(PortalUser $user, int $requestId): DocumentRequest
    {
        return $this->visibleRequests($user)
            ->with(['contact', 'requirementDefinition', 'uploads'])
            ->whereKey($requestId)
            ->firstOrFail();
    }

    public function uploadFor(PortalUser $user, int $uploadId): DocumentUpload
    {
        return $this->visibleUploads($user)
            ->whereKey($uploadId)
            ->firstOrFail();
    }

    public function subjectForRequest(PortalUser $user, DocumentRequest $request): PortalDocumentSubject
    {
        return $this->subjectDefinition(
            user: $user,
            subjectType: $this->subjectType($request),
            subjectId: $this->subjectId($request),
        );
    }

    public function subjectForUpload(PortalUser $user, DocumentUpload $upload): PortalDocumentSubject
    {
        return $this->subjectDefinition(
            user: $user,
            subjectType: $this->subjectType($upload),
            subjectId: $this->subjectId($upload),
        );
    }


    /** @return Builder<DocumentRequest> */
    private function visibleRequests(PortalUser $user): Builder
    {
        return $this->applyAllowedSubjects(DocumentRequest::query(), $user)
            ->whereNotIn('status', [
                DocumentRequest::STATUS_DRAFT,
                DocumentRequest::STATUS_CANCELLED,
            ]);
    }

    /** @return Builder<DocumentUpload> */
    private function visibleUploads(PortalUser $user): Builder
    {
        return $this->applyAllowedSubjects(DocumentUpload::query(), $user)
            ->whereNotIn('status', [
                DocumentUpload::STATUS_ARCHIVED,
                DocumentUpload::STATUS_DELETED,
            ]);
    }

    /**
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    private function applyAllowedSubjects(Builder $query, PortalUser $user): Builder
    {
        $definitions = $this->subjects->forUser($user);

        if ($definitions === []) {
            return $query->whereRaw('1 = 0');
        }

        $contactIds = collect($definitions)
            ->filter(static fn (PortalDocumentSubject $definition): bool => $definition->subject instanceof Contact)
            ->map(static fn (PortalDocumentSubject $definition): int => (int) $definition->subject->getKey())
            ->unique()
            ->values()
            ->all();

        return $query->where(function (Builder $visible) use ($definitions, $contactIds): void {
            foreach ($definitions as $definition) {
                $visible->orWhere(function (Builder $subject) use ($definition): void {
                    $subject
                        ->where('subject_type', $definition->subject->getMorphClass())
                        ->where('subject_id', $definition->subject->getKey());
                });
            }

            if ($contactIds !== []) {
                $visible->orWhere(function (Builder $contactOnly) use ($contactIds): void {
                    $contactOnly
                        ->whereNull('subject_type')
                        ->whereNull('subject_id')
                        ->whereIn('contact_id', $contactIds);
                });
            }
        });
    }

    private function subjectDefinition(
        PortalUser $user,
        ?string $subjectType,
        ?int $subjectId,
    ): PortalDocumentSubject {
        foreach ($this->subjects->forUser($user) as $definition) {
            if ($definition->subject->getMorphClass() === $subjectType
                && (int) $definition->subject->getKey() === $subjectId) {
                return $definition;
            }
        }

        abort(404);
    }

    private function subjectType(DocumentRequest|DocumentUpload $record): ?string
    {
        if (is_string($record->subject_type) && trim($record->subject_type) !== '') {
            return $record->subject_type;
        }

        return $record->contact_id !== null
            ? (new Contact())->getMorphClass()
            : null;
    }

    private function subjectId(DocumentRequest|DocumentUpload $record): ?int
    {
        if ($record->subject_id !== null) {
            return (int) $record->subject_id;
        }

        return $record->contact_id !== null
            ? (int) $record->contact_id
            : null;
    }
}