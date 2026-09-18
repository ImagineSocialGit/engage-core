<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\MessageAttachmentAuthoringService;
use App\Modules\Messaging\Services\MessageAttachmentRegistry;
use App\Modules\Messaging\View\Components\MessageMediaAuthoring;
use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentUploadSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use App\Support\ModuleIntegrations\Messaging\Contracts\SelectableMessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\UnavailableMessageMediaLibrary;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

class MessageAttachmentAuthoringTest extends TestCase
{
    public function test_attachment_picker_renders_when_body_media_is_unavailable(): void
    {
        Storage::fake('spaces');
        Storage::disk('spaces')->put('documents/guide.pdf', 'guide');
        app()->instance(MessageMediaLibrary::class, new UnavailableMessageMediaLibrary);
        app()->instance(MessageAttachmentRegistry::class, new MessageAttachmentRegistry([
            $this->documents(),
        ]));
        app('view')->share('errors', new ViewErrorBag);
        Blade::component('messaging.message-media-authoring', MessageMediaAuthoring::class);

        $html = Blade::render('<x-messaging.message-media-authoring :contact-id="42" />');

        $this->assertStringContainsString('Attach files', $html);
        $this->assertStringContainsString('documents:12', $html);
        $this->assertStringContainsString('attachment_upload', $html);
    }

    public function test_authoring_keeps_stable_references_and_enforces_contact_ownership(): void
    {
        Storage::fake('spaces');
        Storage::disk('spaces')->put('documents/guide.pdf', 'guide');
        $service = new MessageAttachmentAuthoringService(new MessageAttachmentRegistry([
            $this->documents(),
        ]));

        $payload = $service->apply(['subject' => 'Hello'], ['documents:12'], 42);
        $this->assertSame([['source' => 'documents', 'id' => '12']], $payload['attachments']);
        $this->assertArrayNotHasKey('attachments', $service->apply($payload, [], 42));

        $this->expectException(InvalidArgumentException::class);
        $service->apply(['subject' => 'Hello'], ['documents:12'], 99);
    }

    public function test_enabled_documents_contribute_main_navigation(): void
    {
        config()->set('modules.enabled', ['documents']);

        $items = app(ModuleManager::class)->navigationItems();
        $this->assertContains('crm.documents.index', array_column($items, 'route'));
    }

    private function documents(): MessageAttachmentSource&SelectableMessageAttachmentSource&MessageAttachmentUploadSource
    {
        return new class implements MessageAttachmentSource, SelectableMessageAttachmentSource, MessageAttachmentUploadSource
        {
            public function uploadLabel(): string
            {
                return 'Private document';
            }

            public function storeForMessage(
                UploadedFile $file,
                ?int $contactId = null,
                ?Model $uploadedBy = null,
            ): AttachmentFile {
                throw new \RuntimeException('This test only checks the upload control.');
            }
            public function key(): string
            {
                return 'documents';
            }

            public function find(string $id): ?AttachmentFile
            {
                return $id === '12' ? new AttachmentFile(
                    source: 'documents',
                    id: '12',
                    disk: 'spaces',
                    path: 'documents/guide.pdf',
                    filename: 'guide.pdf',
                    mimeType: 'application/pdf',
                    sizeBytes: 5,
                    contactId: 42,
                ) : null;
            }

            public function selectable(?int $contactId = null): array
            {
                return [$this->find('12')];
            }
        };
    }
}