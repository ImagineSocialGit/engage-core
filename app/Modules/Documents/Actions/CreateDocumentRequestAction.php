<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentReviewEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateDocumentRequestAction
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $meta
     */
    public function handle(
        Model $subject,
        string $requirementKey,
        ?Contact $contact = null,
        ?Model $requestedBy = null,
        ?Model $assignedTo = null,
        ?string $title = null,
        ?string $instructions = null,
        string $priority = DocumentRequest::PRIORITY_NORMAL,
        ?CarbonInterface $expiresAt = null,
        string $source = 'manual',
        array $settings = [],
        array $meta = [],
    ): DocumentRequest {
        $this->assertPersisted($subject, 'subject');
        $this->assertOptionalPersisted($contact, 'contact');
        $this->assertOptionalPersisted($requestedBy, 'requested-by actor');
        $this->assertOptionalPersisted($assignedTo, 'assignee');

        if ($subject instanceof Contact) {
            if ($contact !== null && ! $contact->is($subject)) {
                throw new InvalidArgumentException(
                    'A Contact document request cannot reference a different contact.',
                );
            }

            $contact ??= $subject;
        }

        $requirementKey = trim($requirementKey);

        if ($requirementKey === '') {
            throw new InvalidArgumentException(
                'Document request creation requires a document requirement key.',
            );
        }

        $requirement = DocumentRequirementDefinition::query()
            ->where('key', $requirementKey)
            ->first();

        if (! $requirement instanceof DocumentRequirementDefinition
            || $requirement->status !== DocumentRequirementDefinition::STATUS_ACTIVE
        ) {
            throw new DomainException(
                "Active document requirement [{$requirementKey}] is not available.",
            );
        }

        if (! in_array($priority, [
            DocumentRequest::PRIORITY_LOW,
            DocumentRequest::PRIORITY_NORMAL,
            DocumentRequest::PRIORITY_HIGH,
            DocumentRequest::PRIORITY_URGENT,
        ], true)) {
            throw new InvalidArgumentException(
                'Document request priority is invalid.',
            );
        }

        $source = trim($source);

        if ($source === '' || mb_strlen($source) > 255) {
            throw new InvalidArgumentException(
                'Document request source must be 1-255 characters.',
            );
        }

        $resolvedTitle = trim((string) $title) !== ''
            ? trim((string) $title)
            : trim((string) $requirement->name);

        if ($resolvedTitle === '' || mb_strlen($resolvedTitle) > 255) {
            throw new InvalidArgumentException(
                'Document request title must be 1-255 characters.',
            );
        }

        $resolvedInstructions = $instructions !== null
            ? $this->nullableString($instructions)
            : $this->nullableString($requirement->instructions);

        $requestedAt = CarbonImmutable::now('UTC');
        $resolvedExpiresAt = $expiresAt instanceof CarbonInterface
            ? CarbonImmutable::instance($expiresAt)->utc()
            : null;

        return DB::transaction(function () use (
            $subject,
            $requirement,
            $contact,
            $requestedBy,
            $assignedTo,
            $resolvedTitle,
            $resolvedInstructions,
            $priority,
            $resolvedExpiresAt,
            $source,
            $settings,
            $meta,
            $requestedAt,
        ): DocumentRequest {
            $request = DocumentRequest::query()->create([
                'document_requirement_definition_id' => $requirement->getKey(),
                'contact_id' => $contact?->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'requested_by_type' => $requestedBy?->getMorphClass(),
                'requested_by_id' => $requestedBy?->getKey(),
                'assigned_to_type' => $assignedTo?->getMorphClass(),
                'assigned_to_id' => $assignedTo?->getKey(),
                'title' => $resolvedTitle,
                'instructions' => $resolvedInstructions,
                'status' => DocumentRequest::STATUS_PENDING,
                'priority' => $priority,
                'requested_at' => $requestedAt,
                'expires_at' => $resolvedExpiresAt,
                'source' => $source,
                'settings' => $settings !== [] ? $settings : null,
                'meta' => $meta !== [] ? $meta : null,
            ]);

            DocumentReviewEvent::query()->create([
                'document_request_id' => $request->getKey(),
                'actor_type' => $requestedBy?->getMorphClass(),
                'actor_id' => $requestedBy?->getKey(),
                'event' => DocumentReviewEvent::EVENT_REQUESTED,
                'from_status' => null,
                'to_status' => DocumentRequest::STATUS_PENDING,
                'occurred_at' => $requestedAt,
            ]);

            return $request->load('requirementDefinition');
        });
    }

    private function assertPersisted(Model $model, string $label): void
    {
        if (! $model->exists || $model->getKey() === null) {
            throw new InvalidArgumentException(
                "Document request {$label} must be persisted.",
            );
        }
    }

    private function assertOptionalPersisted(?Model $model, string $label): void
    {
        if ($model !== null) {
            $this->assertPersisted($model, $label);
        }
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