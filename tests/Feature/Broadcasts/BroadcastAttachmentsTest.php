<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Services\MessageAttachmentRegistry;
use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentUploadSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\SelectableMessageAttachmentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BroadcastAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutExceptionHandling();

        config(['modules.modules.broadcasts.enabled' => true]);
        Storage::fake('spaces');
        Storage::disk('spaces')->put('broadcasts/1.pdf', '%PDF-1.4 existing');
        app()->instance(MessageAttachmentRegistry::class, new MessageAttachmentRegistry([$this->source()]));
    }

    public function test_selected_document_survives_broadcast_create_edit_and_explicit_removal(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('crm.broadcasts.store'), array_merge(
            $this->broadcastInput(),
            ['attachments_present' => '1', 'attachment_refs' => ['documents:1']],
        ));

        $broadcast = $this->createdBroadcast($response);
        $reference = [['source' => 'documents', 'id' => '1']];
        $this->assertEqualsCanonicalizing($reference, $broadcast->messagePayload()['attachments']);

        $this->actingAs($user)->get(route('crm.broadcasts.edit', $broadcast))
            ->assertOk()
            ->assertSee('value="documents:1"', false)
            ->assertSee('checked', false);

        $this->actingAs($user)->patch(route('crm.broadcasts.update', $broadcast), [
            'name' => 'Updated broadcast',
            'subject' => 'Updated subject',
            'body' => 'Updated body',
            'recipient_filter_type' => 'all',
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing($reference, $broadcast->refresh()->messagePayload()['attachments']);

        $this->actingAs($user)->patch(route('crm.broadcasts.update', $broadcast), [
            'name' => 'Updated broadcast',
            'subject' => 'Updated subject',
            'body' => 'Updated body',
            'recipient_filter_type' => 'all',
            'attachments_present' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('attachments', $broadcast->refresh()->messagePayload());
    }

    public function test_uploaded_document_reference_is_saved_in_broadcast_message_version(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('crm.broadcasts.store'), array_merge(
            $this->broadcastInput(),
            [
                'attachments_present' => '1',
                'attachment_upload_source' => 'documents',
                'attachment_upload' => UploadedFile::fake()->createWithContent('new.pdf', '%PDF-1.4 uploaded'),
            ],
        ));

        $broadcast = $this->createdBroadcast($response);
        $this->assertEqualsCanonicalizing(
            [['source' => 'documents', 'id' => '2']],
            $broadcast->messagePayload()['attachments'],
        );
        Storage::disk('spaces')->assertExists('broadcasts/2.pdf');
    }

    /** @return array<string, string> */
    private function broadcastInput(): array
    {
        return [
            'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            'intent' => 'draft',
            'name' => 'File update',
            'subject' => 'Read the document',
            'body' => 'Attached is the document.',
            'recipient_filter_type' => 'all',
        ];
    }

    private function createdBroadcast(TestResponse $response): Broadcast
    {
        $broadcast = Broadcast::query()->first();
        $errors = session('errors');

        $this->assertNotNull($broadcast, sprintf(
            'Broadcast POST did not create a row. HTTP %d; Location: %s; validation errors: %s; flash error: %s',
            $response->status(),
            $response->headers->get('Location', '(none)'),
            $errors instanceof \Illuminate\Support\ViewErrorBag
                ? json_encode($errors->all(), JSON_UNESCAPED_SLASHES)
                : '(none)',
            (string) session('error', '(none)'),
        ));

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));
        $response->assertSessionHasNoErrors();

        return $broadcast;
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
                return array_values(array_filter([$this->find('1'), $this->find('2')]));
            }

            public function find(string $id): ?AttachmentFile
            {
                if (! in_array($id, ['1', '2'], true)) {
                    return null;
                }

                $path = 'broadcasts/'.$id.'.pdf';
                if (! Storage::disk('spaces')->exists($path)) {
                    return null;
                }

                return new AttachmentFile(
                    source: 'documents',
                    id: $id,
                    disk: 'spaces',
                    path: $path,
                    filename: $id.'.pdf',
                    mimeType: 'application/pdf',
                    sizeBytes: Storage::disk('spaces')->size($path),
                    contactId: null,
                );
            }

            public function storeForMessage(
                UploadedFile $file,
                ?int $contactId = null,
                ?Model $uploadedBy = null,
            ): AttachmentFile {
                Storage::disk('spaces')->put('broadcasts/2.pdf', file_get_contents($file->getRealPath()));

                return $this->find('2');
            }
        };
    }
}