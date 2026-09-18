<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\MessageAttachmentAuthoringService;
use App\Modules\Messaging\Services\MessageAttachmentRegistry;
use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentUploadSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\SelectableMessageAttachmentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class MessageAttachmentInlineUploadTest extends TestCase
{
    public function test_upload_creates_one_stored_file_and_adds_only_its_reference_to_the_message(): void
    {
        Storage::fake('spaces');
        $source = $this->source();
        $service = new MessageAttachmentAuthoringService(new MessageAttachmentRegistry([$source]));

        $result = $service->apply(
            payload: ['subject' => 'File attached'],
            values: [],
            contactId: 42,
            upload: UploadedFile::fake()->createWithContent('agreement.pdf', 'sample agreement'),
            uploadSource: 'documents',
        );

        $this->assertSame([['source' => 'documents', 'id' => '12']], $result['attachments']);
        $this->assertSame('File attached', $result['subject']);
        Storage::disk('spaces')->assertExists('documents/agreement.pdf');
        $this->assertCount(1, Storage::disk('spaces')->allFiles());
    }

    public function test_oversize_upload_is_rejected_before_storage(): void
    {
        Storage::fake('spaces');
        config()->set('messaging.email.attachments.max_file_bytes', 4);
        $service = new MessageAttachmentAuthoringService(new MessageAttachmentRegistry([$this->source()]));

        try {
            $service->apply(
                payload: [],
                values: [],
                contactId: 42,
                upload: UploadedFile::fake()->createWithContent('large.pdf', '12345'),
                uploadSource: 'documents',
            );
            $this->fail('Expected attachment size validation to reject the upload.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], Storage::disk('spaces')->allFiles());
        }
    }

    private function source(): MessageAttachmentSource&SelectableMessageAttachmentSource&MessageAttachmentUploadSource
    {
        return new class implements MessageAttachmentSource, SelectableMessageAttachmentSource, MessageAttachmentUploadSource
        {
            public function key(): string
            {
                return 'documents';
            }

            public function uploadLabel(): string
            {
                return 'Private document';
            }

            public function selectable(?int $contactId = null): array
            {
                $file = $this->find('12');

                return $file !== null && $file->contactId === $contactId ? [$file] : [];
            }

            public function find(string $id): ?AttachmentFile
            {
                if ($id !== '12' || ! Storage::disk('spaces')->exists('documents/agreement.pdf')) {
                    return null;
                }

                return new AttachmentFile(
                    source: 'documents', id: $id, disk: 'spaces',
                    path: 'documents/agreement.pdf', filename: 'agreement.pdf',
                    mimeType: 'application/pdf', sizeBytes: 16, contactId: 42,
                );
            }

            public function storeForMessage(
                UploadedFile $file,
                ?int $contactId = null,
                ?Model $uploadedBy = null,
            ): AttachmentFile {
                Storage::disk('spaces')->put('documents/agreement.pdf', file_get_contents($file->getRealPath()));

                return $this->find('12');
            }
        };
    }
}