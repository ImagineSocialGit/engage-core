<?php

namespace App\Support\ModuleIntegrations\Documents\Portal;

use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Services\DocumentAttachmentLibrary;
use App\Modules\Portal\Services\PortalAuthContext;
use App\Modules\Portal\Services\PortalNavigationRegistry;
use App\Modules\Portal\Services\PortalPresentationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PortalDocumentController
{
    public function index(
        PortalAuthContext $context,
        PortalNavigationRegistry $navigation,
        PortalPresentationResolver $presentation,
        PortalDocumentAccess $access,
    ): View {
        $user = $context->requireUser();

        $requests = $access->requestsFor($user);
        $uploads = $access->uploadsFor($user);

        return view('portal.documents.index', [
            'portalUser' => $user,
            'requests' => $requests,
            'uploads' => $uploads,
            'requestSubjects' => $requests->mapWithKeys(
                fn (DocumentRequest $request): array => [
                    (int) $request->getKey() => $access->subjectForRequest($user, $request),
                ],
            ),
            'uploadSubjects' => $uploads->mapWithKeys(
                fn ($upload): array => [
                    (int) $upload->getKey() => $access->subjectForUpload($user, $upload),
                ],
            ),
            'navigation' => $navigation->forUser($user),
            'presentation' => $presentation->resolve('Documents'),
        ]);
    }

    public function showRequest(
        int $documentRequest,
        PortalAuthContext $context,
        PortalNavigationRegistry $navigation,
        PortalPresentationResolver $presentation,
        PortalDocumentAccess $access,
    ): View {
        $user = $context->requireUser();
        $request = $access->requestFor($user, $documentRequest);

        return view('portal.documents.request', [
            'portalUser' => $user,
            'documentRequest' => $request,
            'subject' => $access->subjectForRequest($user, $request),
            'navigation' => $navigation->forUser($user),
            'presentation' => $presentation->resolve($request->title),
            'maximumMb' => round(max(1, (int) config('documents.max_upload_kilobytes', 307200)) / 1024, 1),
            'canUpload' => $this->uploadable($request),
        ]);
    }

    public function storeUpload(
        Request $httpRequest,
        int $documentRequest,
        PortalAuthContext $context,
        PortalDocumentAccess $access,
        DocumentAttachmentLibrary $library,
    ): RedirectResponse {
        $user = $context->requireUser();
        $request = $access->requestFor($user, $documentRequest);
        if (! $this->uploadable($request)) {
            throw ValidationException::withMessages([
                'file' => 'This document request is not accepting uploads.',
            ]);
        }

        $validated = $httpRequest->validate([
            'file' => [
                'required',
                'file',
                'max:'.max(1, (int) config('documents.max_upload_kilobytes', 307200)),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'expires_on' => ['nullable', 'date'],
        ]);

        $subject = $access->subjectForRequest($user, $request)->subject;
        $expiresAt = $this->expiration($validated['expires_on'] ?? null);

        try {
            $library->store(
                file: $httpRequest->file('file'),
                contact: $request->contact,
                request: $request,
                title: $validated['title'] ?? null,
                uploadedBy: $user,
                subject: $subject,
                requirement: $request->requirementDefinition,
                expiresAt: $expiresAt,
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('portal.documents.requests.show', ['documentRequest' => $request->getKey()])
            ->with('success', 'Document uploaded.');
    }

    public function download(
        int $documentUpload,
        PortalAuthContext $context,
        PortalDocumentAccess $access,
        DocumentAttachmentLibrary $library,
    ): StreamedResponse {
        $user = $context->requireUser();
        $upload = $access->uploadFor($user, $documentUpload);
        $attachment = $library->find((int) $upload->getKey());

        abort_if($attachment === null, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->filename,
            [
                'Content-Type' => $attachment->mimeType,
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    private function uploadable(DocumentRequest $request): bool
    {
        return in_array($request->status, [
            DocumentRequest::STATUS_PENDING,
            DocumentRequest::STATUS_SENT,
            DocumentRequest::STATUS_VIEWED,
            DocumentRequest::STATUS_UPLOADED,
            DocumentRequest::STATUS_REPLACEMENT_REQUESTED,
        ], true);
    }

    private function expiration(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timezone = (string) config('client.timezone', config('app.timezone', 'UTC'));

        return CarbonImmutable::parse(trim($value), $timezone)
            ->endOfDay()
            ->utc();
    }
}