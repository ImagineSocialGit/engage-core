<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Actions\CreateDocumentRequestAction;
use App\Modules\Documents\Actions\ReviewDocumentUploadAction;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentReviewEvent;
use App\Modules\Documents\Models\DocumentUpload;
use App\Modules\Documents\Services\DocumentAttachmentLibrary;
use App\Modules\Documents\Services\DocumentEvidenceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DocumentRequestLifecycleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents-lifecycle-test');
        config()->set('documents.disk', 'documents-lifecycle-test');
    }

    public function test_request_upload_review_and_evidence_resolution_use_documents_owned_actions(): void
    {
        $contact = Contact::factory()->create();
        $reviewer = User::factory()->create();
        $requirement = DocumentRequirementDefinition::factory()->create([
            'key' => 'lifecycle_certificate',
            'status' => DocumentRequirementDefinition::STATUS_ACTIVE,
            'requires_review' => true,
        ]);

        $request = app(CreateDocumentRequestAction::class)->handle(
            subject: $contact,
            requirementKey: $requirement->key,
            requestedBy: $reviewer,
            source: 'test',
        );

        $this->assertSame(DocumentRequest::STATUS_PENDING, $request->status);
        $this->assertSame((int) $contact->getKey(), (int) $request->contact_id);
        $this->assertSame($contact->getMorphClass(), $request->subject_type);
        $this->assertSame((int) $contact->getKey(), (int) $request->subject_id);
        $this->assertDatabaseHas('document_review_events', [
            'document_request_id' => $request->getKey(),
            'event' => DocumentReviewEvent::EVENT_REQUESTED,
        ]);

        $expiresAt = CarbonImmutable::parse('2027-03-01 23:59:59 UTC');

        $upload = app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'certificate.pdf',
                20,
                'application/pdf',
            ),
            contact: $contact,
            request: $request,
            subject: $contact,
            requirement: $requirement,
            expiresAt: $expiresAt,
        );

        $this->assertSame(DocumentUpload::REVIEW_STATUS_PENDING, $upload->review_status);
        $this->assertFalse(app(DocumentEvidenceResolver::class)->satisfies(
            $contact,
            $requirement->key,
            CarbonImmutable::parse('2027-02-01 UTC'),
        ));

        $approved = app(ReviewDocumentUploadAction::class)->handle(
            upload: $upload,
            decision: ReviewDocumentUploadAction::DECISION_APPROVED,
            actor: $reviewer,
        );

        $this->assertSame(DocumentUpload::STATUS_APPROVED, $approved->status);
        $this->assertSame(DocumentUpload::REVIEW_STATUS_APPROVED, $approved->review_status);
        $this->assertSame(DocumentRequest::STATUS_SATISFIED, $request->fresh()->status);
        $this->assertTrue(app(DocumentEvidenceResolver::class)->satisfies(
            $contact,
            $requirement->key,
            CarbonImmutable::parse('2027-02-01 UTC'),
        ));
        $this->assertFalse(app(DocumentEvidenceResolver::class)->satisfies(
            $contact,
            $requirement->key,
            CarbonImmutable::parse('2027-03-02 UTC'),
        ));
    }

    public function test_rejection_requests_replacement_and_records_review_history(): void
    {
        $contact = Contact::factory()->create();
        $reviewer = User::factory()->create();
        $requirement = DocumentRequirementDefinition::factory()->create([
            'key' => 'replacement_certificate',
            'status' => DocumentRequirementDefinition::STATUS_ACTIVE,
            'requires_review' => true,
        ]);

        $request = app(CreateDocumentRequestAction::class)->handle(
            subject: $contact,
            requirementKey: $requirement->key,
            source: 'test',
        );

        $upload = app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'replacement.pdf',
                20,
                'application/pdf',
            ),
            contact: $contact,
            request: $request,
            subject: $contact,
            requirement: $requirement,
        );

        $rejected = app(ReviewDocumentUploadAction::class)->handle(
            upload: $upload,
            decision: ReviewDocumentUploadAction::DECISION_REJECTED,
            actor: $reviewer,
            reason: 'Unreadable',
        );

        $this->assertSame(DocumentUpload::STATUS_REJECTED, $rejected->status);
        $this->assertSame(DocumentUpload::REVIEW_STATUS_REJECTED, $rejected->review_status);
        $this->assertSame(
            DocumentRequest::STATUS_REPLACEMENT_REQUESTED,
            $request->fresh()->status,
        );
        $this->assertDatabaseHas('document_review_events', [
            'document_request_id' => $request->getKey(),
            'document_upload_id' => $upload->getKey(),
            'event' => DocumentReviewEvent::EVENT_REJECTED,
            'reason' => 'Unreadable',
        ]);
    }
}