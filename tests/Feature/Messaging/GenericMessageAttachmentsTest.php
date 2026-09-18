<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\Internal\InternalEmailNotificationPayload;
use App\Modules\Messaging\Services\MessageAttachmentRegistry;
use App\Modules\Messaging\Services\ScheduledMessagePayloadCanonicalizer;
use App\Support\ModuleIntegrations\Documents\Contracts\DocumentAttachmentSource;
use App\Support\ModuleIntegrations\Documents\DocumentAttachment;
use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Documents\DocumentMessageAttachmentSource;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class GenericMessageAttachmentsTest extends TestCase
{
    public function test_email_attaches_stored_files_from_multiple_module_sources(): void
    {
        config()->set('messaging.email.from.transactional.address', 'sender@example.test');
        Storage::fake('spaces');
        Storage::disk('spaces')->put('documents/agreement.pdf', 'agreement');
        Storage::disk('spaces')->put('media/portrait.png', 'portrait');

        app()->instance(MessageAttachmentRegistry::class, new MessageAttachmentRegistry([
            $this->source('documents', '12', 'documents/agreement.pdf', 'agreement.pdf', 9, 42),
            $this->source('media', 'asset-uuid', 'media/portrait.png', 'portrait.png', 8),
        ]));

        $payload = EmailPayload::fromArray([
            'to' => 'recipient@example.test',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'generic',
            'message_type' => 'example',
            'contact_id' => 42,
            'subject' => 'Files',
            'body' => 'Files attached.',
            'attachments' => [
                ['source' => 'documents', 'id' => '12'],
                ['source' => 'media', 'id' => 'asset-uuid'],
            ],
        ]);

        $mailable = $payload->mailable();

        $this->assertCount(2, $mailable->diskAttachments);
        $this->assertSame('agreement.pdf', $mailable->diskAttachments[0]['name']);
        $this->assertSame('portrait.png', $mailable->diskAttachments[1]['name']);
        $this->assertStringNotContainsString('agreement.pdf', $payload->html());
    }

    public function test_marketing_email_can_attach_media_without_a_contact_document(): void
    {
        config()->set('messaging.email.from.marketing.address', 'sender@example.test');
        Storage::fake('spaces');
        Storage::disk('spaces')->put('media/guide.pdf', 'guide');
        app()->instance(MessageAttachmentRegistry::class, new MessageAttachmentRegistry([
            $this->source('media', 'guide', 'media/guide.pdf', 'guide.pdf', 5),
        ]));

        $payload = EmailPayload::fromArray([
            'to' => 'reader@example.test',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'generic',
            'message_type' => 'example',
            'subject' => 'Guide',
            'body' => 'The guide is attached.',
            'attachments' => [['source' => 'media', 'id' => 'guide']],
        ]);

        $this->assertSame('guide.pdf', $payload->mailable()->diskAttachments[0]['name']);
    }

    public function test_internal_notification_can_attach_a_contact_owned_document(): void
    {
        config()->set('messaging.internal_notifications.email.from_address', 'staff@example.test');
        Storage::fake('spaces');
        Storage::disk('spaces')->put('documents/review.pdf', 'review');
        app()->instance(MessageAttachmentRegistry::class, new MessageAttachmentRegistry([
            $this->source('documents', '31', 'documents/review.pdf', 'review.pdf', 6, 42),
        ]));

        $input = [
            'to' => 'team@example.test',
            'channel' => 'email',
            'purpose' => 'internal',
            'scope' => 'staff_alerts',
            'message_type' => 'document_review',
            'contact_id' => 42,
            'subject' => 'Review document',
            'body' => ['A document is ready for review.'],
            'attachments' => [['source' => 'documents', 'id' => '31']],
        ];

        $canonical = app(ScheduledMessagePayloadCanonicalizer::class)->canonicalize(
            InternalEmailNotificationPayload::class,
            $input,
        );
        $this->assertSame($input['attachments'], $canonical['attachments']);
        $this->assertSame(42, $canonical['contact_id']);

        $payload = InternalEmailNotificationPayload::fromArray($input);
        $this->assertSame('review.pdf', $payload->mailable()->diskAttachments[0]['name']);
    }

    public function test_a_future_generated_source_uses_the_same_reference_contract(): void
    {
        Storage::fake('spaces');
        Storage::disk('spaces')->put('invoices/invoice-99.pdf', 'invoice');
        $registry = new MessageAttachmentRegistry([
            $this->source('billing', 'invoice-99', 'invoices/invoice-99.pdf', 'invoice.pdf', 7, 42),
        ]);

        $files = $registry->resolve([['source' => 'billing', 'id' => 'invoice-99']], 42);

        $this->assertSame('invoice.pdf', $files[0]->filename);
    }

    public function test_document_adapter_preserves_document_contact_and_private_storage_reference(): void
    {
        $documentSource = new class implements DocumentAttachmentSource {
            public function find(int $documentUploadId): ?DocumentAttachment
            {
                return $documentUploadId !== 12 ? null : new DocumentAttachment(
                    id: 12,
                    contactId: 42,
                    disk: 'spaces',
                    path: 'documents/private.pdf',
                    filename: 'private.pdf',
                    mimeType: 'application/pdf',
                    sizeBytes: 100,
                );
            }
        };

        $attachment = (new DocumentMessageAttachmentSource($documentSource))->find('12');

        $this->assertSame('documents', $attachment?->source);
        $this->assertSame(42, $attachment?->contactId);
        $this->assertSame('documents/private.pdf', $attachment?->path);
    }

    public function test_contact_owned_documents_cannot_be_sent_to_another_contact(): void
    {
        Storage::fake('spaces');
        Storage::disk('spaces')->put('documents/private.pdf', 'private');
        $registry = new MessageAttachmentRegistry([
            $this->source('documents', '5', 'documents/private.pdf', 'private.pdf', 7, 42),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('another contact');
        $registry->resolve([['source' => 'documents', 'id' => '5']], 99);
    }

    public function test_oversized_media_cannot_be_included_as_email_attachment(): void
    {
        Storage::fake('spaces');
        Storage::disk('spaces')->put('media/movie.mp4', 'video');
        config()->set('messaging.email.attachments.max_file_bytes', 100);
        $registry = new MessageAttachmentRegistry([
            $this->source('media', 'movie', 'media/movie.mp4', 'movie.mp4', 101),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('size limit');
        $registry->resolve([['source' => 'media', 'id' => 'movie']]);
    }

    public function test_email_attachment_references_survive_scheduled_payload_canonicalization(): void
    {
        $payload = app(ScheduledMessagePayloadCanonicalizer::class)->canonicalize(
            EmailPayload::class,
            [
                'to' => 'recipient@example.test',
                'body' => 'Hello',
                'subject' => 'Hi',
                'attachments' => [['source' => 'billing', 'id' => 'invoice-99']],
            ],
        );

        $this->assertSame([['source' => 'billing', 'id' => 'invoice-99']], $payload['attachments']);
    }

    public function test_duplicate_attachment_reference_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailPayload::fromArray([
            'to' => 'recipient@example.test',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'generic',
            'message_type' => 'example',
            'attachments' => [
                ['source' => 'documents', 'id' => '1'],
                ['source' => 'documents', 'id' => '1'],
            ],
        ]);
    }

    private function source(
        string $key,
        string $id,
        string $path,
        string $filename,
        int $size,
        ?int $contactId = null,
    ): MessageAttachmentSource {
        return new class($key, $id, $path, $filename, $size, $contactId) implements MessageAttachmentSource {
            public function __construct(
                private readonly string $sourceKey,
                private readonly string $fileId,
                private readonly string $path,
                private readonly string $filename,
                private readonly int $size,
                private readonly ?int $contactId,
            ) {}

            public function key(): string
            {
                return $this->sourceKey;
            }

            public function find(string $id): ?AttachmentFile
            {
                return $id !== $this->fileId ? null : new AttachmentFile(
                    source: $this->sourceKey,
                    id: $id,
                    disk: 'spaces',
                    path: $this->path,
                    filename: $this->filename,
                    mimeType: 'application/octet-stream',
                    sizeBytes: $this->size,
                    contactId: $this->contactId,
                );
            }
        };
    }
}