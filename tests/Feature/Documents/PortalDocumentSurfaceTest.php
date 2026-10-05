<?php

namespace Tests\Feature\Documents;

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentUpload;
use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Providers\PortalModuleServiceProvider;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\ModuleIntegrations\Documents\Portal\DocumentsPortalRouteContributor;
use App\Support\ModuleIntegrations\Documents\Portal\PortalDocumentSubjectRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PortalDocumentSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableRuntime();

        Storage::fake('portal-documents-test');
        config()->set('documents.disk', 'portal-documents-test');
    }

    public function test_portal_subject_registry_exposes_only_active_linked_contacts(): void
    {
        $user = PortalUser::factory()->active()->withPassword()->create();
        $linked = Contact::factory()->create();
        $other = Contact::factory()->create();

        PortalContactLink::factory()->create([
            'portal_user_id' => $user->getKey(),
            'contact_id' => $linked->getKey(),
            'status' => PortalContactLink::STATUS_ACTIVE,
        ]);

        $subjects = app(PortalDocumentSubjectRegistry::class)->forUser($user);

        $this->assertTrue(collect($subjects)->contains(
            fn ($definition): bool => $definition->subject->is($linked),
        ));
        $this->assertFalse(collect($subjects)->contains(
            fn ($definition): bool => $definition->subject->is($other),
        ));
    }

    public function test_verified_portal_user_can_upload_requested_document_for_an_authorized_contact(): void
    {
        $user = PortalUser::factory()->active()->withPassword()->create();
        $contact = Contact::factory()->create();
        $this->link($user, $contact);
        Auth::guard('portal')->login($user);

        $requirement = DocumentRequirementDefinition::factory()->create([
            'key' => 'proof_of_insurance',
            'status' => DocumentRequirementDefinition::STATUS_ACTIVE,
            'requires_review' => false,
            'allows_multiple_uploads' => true,
        ]);

        $documentRequest = DocumentRequest::factory()->create([
            'document_requirement_definition_id' => $requirement->getKey(),
            'contact_id' => $contact->getKey(),
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'status' => DocumentRequest::STATUS_PENDING,
        ]);

        $this->post(route('portal.documents.requests.uploads.store', [
            'documentRequest' => $documentRequest->getKey(),
        ]), [
            'file' => UploadedFile::fake()->create('insurance.pdf', 20, 'application/pdf'),
            'title' => 'Insurance certificate',
            'expires_on' => '2027-03-01',
        ])->assertRedirect(route('portal.documents.requests.show', [
            'documentRequest' => $documentRequest->getKey(),
        ]));

        $upload = DocumentUpload::query()->sole();

        $this->assertSame($contact->getMorphClass(), $upload->subject_type);
        $this->assertSame((int) $contact->getKey(), (int) $upload->subject_id);
        $this->assertSame((int) $requirement->getKey(), (int) $upload->document_requirement_definition_id);
        $this->assertSame($user->getMorphClass(), $upload->uploaded_by_type);
        $this->assertSame((int) $user->getKey(), (int) $upload->uploaded_by_id);
        $this->assertSame(DocumentUpload::REVIEW_STATUS_APPROVED, $upload->review_status);
        $this->assertSame('2027-03-01', $upload->expires_at?->timezone(config('client.timezone', config('app.timezone', 'UTC')))->toDateString());
        Storage::disk('portal-documents-test')->assertExists($upload->path);
    }

    public function test_portal_user_cannot_open_request_or_download_upload_for_another_subject(): void
    {
        $user = PortalUser::factory()->active()->withPassword()->create();
        $linked = Contact::factory()->create();
        $other = Contact::factory()->create();
        $this->link($user, $linked);
        Auth::guard('portal')->login($user);

        $documentRequest = DocumentRequest::factory()->create([
            'contact_id' => $other->getKey(),
            'subject_type' => $other->getMorphClass(),
            'subject_id' => $other->getKey(),
            'status' => DocumentRequest::STATUS_PENDING,
        ]);

        $upload = DocumentUpload::factory()->create([
            'contact_id' => $other->getKey(),
            'subject_type' => $other->getMorphClass(),
            'subject_id' => $other->getKey(),
        ]);

        $this->get(route('portal.documents.requests.show', [
            'documentRequest' => $documentRequest->getKey(),
        ]))->assertNotFound();

        $this->get(route('portal.documents.uploads.download', [
            'documentUpload' => $upload->getKey(),
        ]))->assertNotFound();
    }

    private function enableRuntime(): void
    {
        $enabled = config('modules.enabled', []);
        $enabled = is_array($enabled) ? $enabled : [];
        config()->set('modules.enabled', array_values(array_unique([
            ...$enabled,
            'documents',
            'portal',
        ])));
        $this->app->forgetInstance(ModuleManager::class);

        $portal = new PortalModuleServiceProvider($this->app);
        $portal->register();
        $portal->boot();
        Auth::forgetGuards();

        $integrations = new IntegrationsModuleServiceProvider($this->app);
        $integrations->register();
        $this->app->forgetInstance(PortalDocumentSubjectRegistry::class);

        $documentPortalRoutes = [
            'portal.documents.index',
            'portal.documents.requests.show',
            'portal.documents.requests.uploads.store',
            'portal.documents.uploads.download',
        ];

        $missingDocumentPortalRoute = collect($documentPortalRoutes)
            ->contains(static fn (string $routeName): bool => ! Route::has($routeName));

        if ($missingDocumentPortalRoute) {
            Route::name('portal.')->group(
                static fn () => app(DocumentsPortalRouteContributor::class)->registerRoutes(),
            );

            Route::getRoutes()->refreshNameLookups();
        }

        foreach ($documentPortalRoutes as $routeName) {
            $this->assertTrue(
                Route::has($routeName),
                "Documents Portal route [{$routeName}] was not registered for the feature test.",
            );
        }
    }

    private function link(PortalUser $user, Contact $contact): void
    {
        PortalContactLink::factory()->create([
            'portal_user_id' => $user->getKey(),
            'contact_id' => $contact->getKey(),
            'status' => PortalContactLink::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
    }
}