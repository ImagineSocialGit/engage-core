<?php

namespace Tests\Feature\Documents;

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentReviewEvent;
use App\Modules\Documents\Models\DocumentUpload;
use App\Modules\Documents\Providers\DocumentsModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_module_is_registered_without_being_enabled_by_default(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'campaigns',
            'broadcasts',
            'webinars',
            'integrations',
            'reporting',
        ]);

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('documents'));
        $this->assertFalse($modules->enabled('documents'));
        $this->assertSame(['core'], $modules->dependencies('documents'));
        $this->assertContains(DocumentsModuleServiceProvider::class, $modules->providers('documents'));
    }

    public function test_documents_foundation_tables_have_durable_generic_columns(): void
    {
        $this->assertTableHasColumns('document_requirement_definitions', [
            'key',
            'name',
            'description',
            'instructions',
            'status',
            'category',
            'is_required_by_default',
            'allows_multiple_uploads',
            'requires_review',
            'accepted_mime_types',
            'max_file_size_kb',
            'expires_after_days',
            'sort_order',
            'source',
            'provider',
            'external_id',
            'external_url',
            'settings',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('document_requests', [
            'document_requirement_definition_id',
            'contact_id',
            'subject_type',
            'subject_id',
            'requested_by_type',
            'requested_by_id',
            'assigned_to_type',
            'assigned_to_id',
            'title',
            'instructions',
            'status',
            'priority',
            'request_token',
            'requested_at',
            'sent_at',
            'opened_at',
            'first_uploaded_at',
            'last_uploaded_at',
            'satisfied_at',
            'waived_at',
            'expired_at',
            'cancelled_at',
            'expires_at',
            'source',
            'provider',
            'external_id',
            'external_url',
            'settings',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('document_uploads', [
            'document_request_id',
            'document_requirement_definition_id',
            'contact_id',
            'subject_type',
            'subject_id',
            'uploaded_by_type',
            'uploaded_by_id',
            'replaces_document_upload_id',
            'title',
            'status',
            'review_status',
            'disk',
            'path',
            'original_filename',
            'mime_type',
            'extension',
            'size_bytes',
            'checksum',
            'storage_visibility',
            'submitted_at',
            'reviewed_at',
            'approved_at',
            'rejected_at',
            'expires_at',
            'source',
            'provider',
            'external_id',
            'external_url',
            'metadata',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('document_review_events', [
            'document_request_id',
            'document_upload_id',
            'actor_type',
            'actor_id',
            'event',
            'from_status',
            'to_status',
            'reason',
            'notes',
            'occurred_at',
            'meta',
            'deleted_at',
        ]);
    }

    public function test_requirement_definitions_have_requests_and_uploads_without_vertical_meaning(): void
    {
        $definition = DocumentRequirementDefinition::factory()->active()->create([
            'key' => 'vaccination_record',
            'name' => 'Vaccination Record',
            'category' => DocumentRequirementDefinition::CATEGORY_HEALTH,
            'accepted_mime_types' => ['application/pdf', 'image/jpeg'],
        ]);

        $request = DocumentRequest::factory()->forRequirement($definition)->create();
        $upload = DocumentUpload::factory()->forRequest($request)->create();

        $this->assertTrue($definition->documentRequests->contains($request));
        $this->assertTrue($definition->documentUploads->contains($upload));
        $this->assertSame('vaccination_record', $definition->key);
        $this->assertSame(['application/pdf', 'image/jpeg'], $definition->accepted_mime_types);
    }

    public function test_document_requests_link_to_contacts_subjects_initiators_assignees_and_uploads(): void
    {
        $contact = Contact::factory()->create();
        $requester = Contact::factory()->create();
        $assignee = Contact::factory()->create();
        $definition = DocumentRequirementDefinition::factory()->active()->create();

        $request = DocumentRequest::factory()
            ->forRequirement($definition)
            ->forSubject($contact)
            ->requestedBy($requester)
            ->assignedTo($assignee)
            ->create([
                'contact_id' => $contact->id,
                'title' => 'Upload signed waiver',
            ]);

        $upload = DocumentUpload::factory()->forRequest($request)->uploadedBy($contact)->create();

        $this->assertTrue($request->requirementDefinition->is($definition));
        $this->assertTrue($request->contact->is($contact));
        $this->assertTrue($request->subject->is($contact));
        $this->assertTrue($request->requestedBy->is($requester));
        $this->assertTrue($request->assignedTo->is($assignee));
        $this->assertTrue($request->uploads->contains($upload));
        $this->assertTrue($upload->documentRequest->is($request));
        $this->assertTrue($upload->uploadedBy->is($contact));
    }

    public function test_document_uploads_track_storage_metadata_replacements_and_review_events(): void
    {
        $contact = Contact::factory()->create();
        $request = DocumentRequest::factory()->forSubject($contact)->create([
            'contact_id' => $contact->id,
        ]);

        $originalUpload = DocumentUpload::factory()->forRequest($request)->create([
            'path' => 'documents/original.pdf',
            'original_filename' => 'original.pdf',
            'metadata' => [
                'page_count' => 2,
            ],
        ]);

        $replacementUpload = DocumentUpload::factory()->forRequest($request)->replaces($originalUpload)->approved()->create([
            'path' => 'documents/replacement.pdf',
            'original_filename' => 'replacement.pdf',
        ]);

        $event = DocumentReviewEvent::factory()
            ->forRequest($request)
            ->forUpload($replacementUpload)
            ->byActor($contact)
            ->approved()
            ->create([
                'from_status' => DocumentUpload::STATUS_PENDING_REVIEW,
            ]);

        $this->assertSame('documents/original.pdf', $originalUpload->path);
        $this->assertSame(2, $originalUpload->metadata['page_count']);
        $this->assertTrue($replacementUpload->replacesDocumentUpload->is($originalUpload));
        $this->assertTrue($originalUpload->replacementUploads->contains($replacementUpload));
        $this->assertTrue($replacementUpload->reviewEvents->contains($event));
        $this->assertTrue($event->documentRequest->is($request));
        $this->assertTrue($event->documentUpload->is($replacementUpload));
        $this->assertTrue($event->actor->is($contact));
        $this->assertSame(DocumentReviewEvent::EVENT_APPROVED, $event->event);
    }

    public function test_document_review_events_are_history_records_not_tasks_or_form_answers(): void
    {
        $request = DocumentRequest::factory()->create();
        $upload = DocumentUpload::factory()->forRequest($request)->rejected()->create();

        $event = DocumentReviewEvent::factory()
            ->forRequest($request)
            ->forUpload($upload)
            ->rejected('blurry_image')
            ->create([
                'notes' => 'The uploaded image is too blurry to review.',
            ]);

        $this->assertSame(DocumentUpload::STATUS_REJECTED, $upload->status);
        $this->assertSame(DocumentUpload::REVIEW_STATUS_REJECTED, $upload->review_status);
        $this->assertSame(DocumentReviewEvent::EVENT_REJECTED, $event->event);
        $this->assertSame('blurry_image', $event->reason);
        $this->assertSame('The uploaded image is too blurry to review.', $event->notes);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function assertTableHasColumns(string $table, array $columns): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Missing column [{$table}.{$column}].",
            );
        }
    }
}
