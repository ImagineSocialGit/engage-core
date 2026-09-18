<?php

namespace App\Modules\Documents\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentUpload;
use App\Modules\Documents\Services\DocumentAttachmentLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentLibraryController extends Controller
{
    public function index(Request $request, ContactVisibility $visibility, UserAccessService $access): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $contactId = $request->integer('contact_id');
        $contact = $contactId > 0 ? Contact::query()->findOrFail($contactId) : null;
        if ($contact !== null && ! $visibility->canView($user, $contact)) {
            abort(404);
        }

        $visibleContacts = $visibility->apply(Contact::query(), $user)->select('id');
        $uploads = DocumentUpload::query()
            ->where(function ($query) use ($visibleContacts, $access, $user): void {
                $query->whereIn('contact_id', $visibleContacts);
                if ($access->isActive($user) && $access->allows($user, 'contacts.view_all')) {
                    $query->orWhereNull('contact_id');
                }
            })
            ->when($contact !== null, fn ($query) => $query->where('contact_id', $contact->getKey()))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('crm.documents.index', [
            'title' => 'Documents',
            'heading' => 'Documents',
            'subheading' => 'Private files stored for use across your workflows.',
            'uploads' => $uploads,
            'contact' => $contact,
            'maximumMb' => round(max(1, (int) config('documents.max_upload_kilobytes', 307200)) / 1024, 1),
        ]);
    }

    public function store(Request $request, ContactVisibility $visibility, UserAccessService $access, DocumentAttachmentLibrary $library): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.max(1, (int) config('documents.max_upload_kilobytes', 307200))],
            'title' => ['nullable', 'string', 'max:255'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'document_request_id' => ['nullable', 'integer', 'exists:document_requests,id'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $documentRequest = isset($validated['document_request_id'])
            ? DocumentRequest::query()->with(['contact', 'requirementDefinition'])->findOrFail($validated['document_request_id'])
            : null;
        $contact = isset($validated['contact_id'])
            ? Contact::query()->findOrFail($validated['contact_id'])
            : $documentRequest?->contact;
        if ($contact !== null) {
            abort_unless($visibility->canView($user, $contact), 404);
        } else {
            abort_unless($access->isActive($user) && $access->allows($user, 'contacts.view_all'), 403);
        }
        if ($documentRequest !== null && ($documentRequest->contact_id === null
            ? $contact !== null : $documentRequest->contact_id !== $contact?->getKey())) {
            throw ValidationException::withMessages(['document_request_id' => 'The request belongs to another contact.']);
        }

        try {
            $library->store(
                file: $request->file('file'),
                contact: $contact,
                request: $documentRequest,
                title: $validated['title'] ?? null,
                uploadedBy: $user,
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return redirect()->route('crm.documents.index', array_filter([
            'contact_id' => $contact?->getKey(),
        ]))->with('success', 'Document uploaded.');
    }

    public function download(DocumentUpload $documentUpload, Request $request, ContactVisibility $visibility, UserAccessService $access, DocumentAttachmentLibrary $library): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if ($documentUpload->contact_id !== null) {
            $contact = Contact::query()->find($documentUpload->contact_id);
            abort_unless($contact !== null && $visibility->canView($user, $contact), 404);
        } else {
            abort_unless($access->isActive($user) && $access->allows($user, 'contacts.view_all'), 404);
        }

        $attachment = $library->find((int) $documentUpload->getKey());
        abort_if($attachment === null, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->mimeType, 'Cache-Control' => 'private, no-store'],
        );
    }
}