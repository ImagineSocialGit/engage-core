<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentUpload;
use App\Modules\Documents\Services\DocumentAttachmentLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PrivateDocumentLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_spaces_object_is_recorded_once_and_exposed_through_attachment_contract(): void
    {
        Storage::fake('spaces');
        config()->set('documents.disk', 'spaces');

        $file = UploadedFile::fake()->createWithContent('agreement.pdf', '%PDF-1.4 sample');
        $upload = app(DocumentAttachmentLibrary::class)->store($file);

        $this->assertSame('spaces', $upload->disk);
        $this->assertSame(DocumentUpload::STORAGE_VISIBILITY_PRIVATE, $upload->storage_visibility);
        $this->assertSame('agreement.pdf', $upload->original_filename);
        $this->assertSame($file->getSize(), $upload->size_bytes);
        $this->assertSame('private', Storage::disk('spaces')->visibility($upload->path));
        Storage::disk('spaces')->assertExists($upload->path);

        $attachment = app(DocumentAttachmentLibrary::class)->find((int) $upload->getKey());
        $this->assertSame($upload->path, $attachment?->path);
        $this->assertSame('agreement.pdf', $attachment?->filename);
    }

    public function test_document_request_size_rule_is_enforced_before_storage(): void
    {
        Storage::fake('spaces');
        config()->set('documents.disk', 'spaces');
        $definition = DocumentRequirementDefinition::factory()->create([
            'max_file_size_kb' => 1,
        ]);
        $request = DocumentRequest::factory()->create([
            'document_requirement_definition_id' => $definition->getKey(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('allowed size');

        app(DocumentAttachmentLibrary::class)->store(
            UploadedFile::fake()->create('large.pdf', 2, 'application/pdf'),
            request: $request,
        );
    }
}