<?php

namespace Tests\Feature\Documents;

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Services\DocumentAttachmentLibrary;
use App\Modules\Documents\Services\DocumentEvidenceResolver;
use App\Support\ModuleIntegrations\Documents\Contracts\DocumentEvidenceSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DocumentEvidenceSourceContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents-evidence-source-test');
        config()->set('documents.disk', 'documents-evidence-source-test');
    }

    public function test_public_evidence_source_resolves_documents_implementation(): void
    {
        $this->assertInstanceOf(
            DocumentEvidenceResolver::class,
            app(DocumentEvidenceSource::class),
        );
    }

    public function test_expiration_requirement_is_explicit_query_semantics(): void
    {
        $subject = Contact::factory()->create();
        $requirement = DocumentRequirementDefinition::factory()->create([
            'key' => 'evidence_source_certificate',
            'status' => DocumentRequirementDefinition::STATUS_ACTIVE,
            'requires_review' => false,
        ]);

        app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'without-expiration.pdf',
                10,
                'application/pdf',
            ),
            subject: $subject,
            requirement: $requirement,
        );

        $source = app(DocumentEvidenceSource::class);
        $validThrough = CarbonImmutable::parse('2027-01-15 00:00:00 UTC');

        $this->assertTrue($source->satisfies(
            subject: $subject,
            requirementKey: $requirement->key,
            validThrough: $validThrough,
        ));

        $this->assertFalse($source->satisfies(
            subject: $subject,
            requirementKey: $requirement->key,
            validThrough: $validThrough,
            requireExpiration: true,
        ));

        app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'with-expiration.pdf',
                10,
                'application/pdf',
            ),
            subject: $subject,
            requirement: $requirement,
            expiresAt: CarbonImmutable::parse('2027-02-01 23:59:59 UTC'),
        );

        $this->assertTrue($source->satisfies(
            subject: $subject,
            requirementKey: $requirement->key,
            validThrough: $validThrough,
            requireExpiration: true,
        ));

        $this->assertFalse($source->satisfies(
            subject: $subject,
            requirementKey: $requirement->key,
            validThrough: CarbonImmutable::parse('2027-02-02 00:00:00 UTC'),
            requireExpiration: true,
        ));
    }
}