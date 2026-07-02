<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentRequest>
 */
class DocumentRequestFactory extends Factory
{
    protected $model = DocumentRequest::class;

    public function definition(): array
    {
        return [
            'document_requirement_definition_id' => DocumentRequirementDefinition::factory(),
            'contact_id' => Contact::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'requested_by_type' => null,
            'requested_by_id' => null,
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'title' => 'Upload requested document',
            'instructions' => 'Please upload the requested document.',
            'status' => DocumentRequest::STATUS_PENDING,
            'priority' => DocumentRequest::PRIORITY_NORMAL,
            'request_token' => Str::random(40),
            'requested_at' => now(),
            'sent_at' => null,
            'opened_at' => null,
            'first_uploaded_at' => null,
            'last_uploaded_at' => null,
            'satisfied_at' => null,
            'waived_at' => null,
            'expired_at' => null,
            'cancelled_at' => null,
            'expires_at' => null,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'settings' => null,
            'meta' => null,
        ];
    }

    public function forRequirement(DocumentRequirementDefinition $definition): self
    {
        return $this->state([
            'document_requirement_definition_id' => $definition->id,
            'title' => $definition->name,
            'instructions' => $definition->instructions,
        ]);
    }

    public function forSubject(Model $subject): self
    {
        return $this->state([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function requestedBy(Model $actor): self
    {
        return $this->state([
            'requested_by_type' => $actor->getMorphClass(),
            'requested_by_id' => $actor->getKey(),
        ]);
    }

    public function assignedTo(Model $assignee): self
    {
        return $this->state([
            'assigned_to_type' => $assignee->getMorphClass(),
            'assigned_to_id' => $assignee->getKey(),
        ]);
    }

    public function satisfied(): self
    {
        return $this->state([
            'status' => DocumentRequest::STATUS_SATISFIED,
            'satisfied_at' => now(),
        ]);
    }
}
