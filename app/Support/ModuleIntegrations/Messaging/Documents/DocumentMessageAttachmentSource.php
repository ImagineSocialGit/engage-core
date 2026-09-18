<?php

namespace App\Support\ModuleIntegrations\Messaging\Documents;

use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentUpload;
use App\Modules\Documents\Services\DocumentAttachmentLibrary;
use App\Support\ModuleIntegrations\Documents\Contracts\DocumentAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Attachments\AttachmentFile;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentUploadSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\SelectableMessageAttachmentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class DocumentMessageAttachmentSource implements MessageAttachmentSource, SelectableMessageAttachmentSource, MessageAttachmentUploadSource
{
    public function __construct(private readonly DocumentAttachmentSource $documents) {}

    public function key(): string
    {
        return 'documents';
    }

    public function uploadLabel(): string
    {
        return 'Private document';
    }

    public function storeForMessage(
        UploadedFile $file,
        ?int $contactId = null,
        ?Model $uploadedBy = null,
    ): AttachmentFile {
        $user = $uploadedBy instanceof User ? $uploadedBy : Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('Sign in to upload an attachment.');
        }

        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;
        if ($contactId !== null && (! $contact instanceof Contact
            || ! app(ContactVisibility::class)->canView($user, $contact))) {
            throw new RuntimeException('That contact is unavailable for document upload.');
        }
        if ($contactId === null) {
            $access = app(UserAccessService::class);
            if (! $access->isActive($user) || ! $access->allows($user, 'contacts.view_all')) {
                throw new RuntimeException('Uploading an unlinked document requires all-contact access.');
            }
        }

        $upload = app(DocumentAttachmentLibrary::class)->store(
            file: $file,
            contact: $contact,
            uploadedBy: $user,
        );
        $attachment = $this->find((string) $upload->getKey());
        if ($attachment === null) {
            throw new RuntimeException('The uploaded document is unavailable.');
        }

        return $attachment;
    }

    public function find(string $id): ?AttachmentFile
    {
        if (preg_match('/^[1-9][0-9]*$/', $id) !== 1 || filter_var($id, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $document = $this->documents->find((int) $id);

        return $document === null ? null : new AttachmentFile(
            source: $this->key(),
            id: $id,
            disk: $document->disk,
            path: $document->path,
            filename: $document->filename,
            mimeType: $document->mimeType,
            sizeBytes: $document->sizeBytes,
            contactId: $document->contactId,
        );
    }

    public function selectable(?int $contactId = null): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        if ($contactId !== null) {
            $contact = Contact::query()->find($contactId);
            if (! $contact instanceof Contact || ! app(ContactVisibility::class)->canView($user, $contact)) {
                return [];
            }
        }

        $access = app(UserAccessService::class);
        $canUseGlobal = $access->isActive($user)
            && $access->allows($user, 'contacts.view_all');

        return DocumentUpload::query()
            ->where('storage_visibility', DocumentUpload::STORAGE_VISIBILITY_PRIVATE)
            ->whereNotNull('disk')
            ->whereNotNull('path')
            ->whereNotNull('size_bytes')
            ->whereNotIn('status', [
                DocumentUpload::STATUS_ARCHIVED,
                DocumentUpload::STATUS_DELETED,
                DocumentUpload::STATUS_SUPERSEDED,
            ])
            ->where(function ($query) use ($contactId, $canUseGlobal): void {
                if ($contactId !== null) {
                    $query->where('contact_id', $contactId);
                    if ($canUseGlobal) {
                        $query->orWhereNull('contact_id');
                    }
                } elseif ($canUseGlobal) {
                    $query->whereNull('contact_id');
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->latest('id')
            ->limit(75)
            ->get()
            ->map(fn (DocumentUpload $upload): ?AttachmentFile => $this->find((string) $upload->getKey()))
            ->filter()
            ->values()
            ->all();
    }
}