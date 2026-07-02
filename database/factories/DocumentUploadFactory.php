<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentUpload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<DocumentUpload>
 */
class DocumentUploadFactory extends Factory
{
    protected $model = DocumentUpload::class;

    public function definition(): array
    {
        return [
            'document_request_id' => DocumentRequest::factory(),
            'document_requirement_definition_id' => DocumentRequirementDefinition::factory(),
            'contact_id' => Contact::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'uploaded_by_type' => null,
            'uploaded_by_id' => null,
            'replaces_document_upload_id' => null,
            'title' => 'Uploaded document',
            'status' => DocumentUpload::STATUS_UPLOADED,
            'review_status' => DocumentUpload::REVIEW_STATUS_PENDING,
            'disk' => 'private',
            'path' => 'documents/example.pdf',
            'original_filename' => 'example.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 123456,
            'checksum' => null,
            'storage_visibility' => DocumentUpload::STORAGE_VISIBILITY_PRIVATE,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'approved_at' => null,
            'rejected_at' => null,
            'expires_at' => null,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'metadata' => null,
            'meta' => null,
        ];
    }

    public function forRequest(DocumentRequest $request): self
    {
        return $this->state([
            'document_request_id' => $request->id,
            'document_requirement_definition_id' => $request->document_requirement_definition_id,
            'contact_id' => $request->contact_id,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
        ]);
    }

    public function forSubject(Model $subject): self
    {
        return $this->state([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function uploadedBy(Model $actor): self
    {
        return $this->state([
            'uploaded_by_type' => $actor->getMorphClass(),
            'uploaded_by_id' => $actor->getKey(),
        ]);
    }

    public function replaces(DocumentUpload $upload): self
    {
        return $this->state([
            'replaces_document_upload_id' => $upload->id,
        ]);
    }

    public function approved(): self
    {
        return $this->state([
            'status' => DocumentUpload::STATUS_APPROVED,
            'review_status' => DocumentUpload::REVIEW_STATUS_APPROVED,
            'reviewed_at' => now(),
            'approved_at' => now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state([
            'status' => DocumentUpload::STATUS_REJECTED,
            'review_status' => DocumentUpload::REVIEW_STATUS_REJECTED,
            'reviewed_at' => now(),
            'rejected_at' => now(),
        ]);
    }
}
