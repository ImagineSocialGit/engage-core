<?php

namespace App\Modules\Media\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Media\Actions\StoreMediaAssetAction;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Requests\StoreMediaAssetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MediaAssetController extends Controller
{
    public function index(Request $request): View
    {
        $showArchived = $request->boolean('archived');
        $assets = MediaAsset::query()
            ->when(
                $showArchived,
                fn ($query) => $query->archived(),
                fn ($query) => $query->active(),
            )
            ->latest('id')
            ->paginate(24)
            ->withQueryString();

        return view('crm.media.index', [
            'title' => 'Media',
            'heading' => 'Media',
            'subheading' => 'Upload reusable images, video, audio, and files for client communications and other system workflows.',
            'assets' => $assets,
            'showArchived' => $showArchived,
            'maxUploadMegabytes' => round(
                max(1, (int) config('media.max_upload_kilobytes', 262144)) / 1024,
                1,
            ),
        ]);
    }

    public function store(
        StoreMediaAssetRequest $request,
        StoreMediaAssetAction $storeMediaAsset,
    ): RedirectResponse {
        $asset = $storeMediaAsset->handle(
            file: $request->file('file'),
            title: $request->input('title'),
            uploadedBy: $request->user(),
        );

        $reused = ! $asset->wasRecentlyCreated;

        if ($asset->isProcessing()) {
            $message = $reused
                ? "This video is already in Media and is still processing — '{$asset->title}'."
                : 'Video uploaded. Media is preparing a web-safe MP4 and poster now.';
            $status = $reused ? 'processing_reused' : 'processing';
        } else {
            $message = $reused
                ? "This file is already in Media — using '{$asset->title}'."
                : 'Media uploaded.';
            $status = $reused ? 'reused' : 'created';
        }

        return redirect()
            ->route('crm.media.index')
            ->with('success', $message)
            ->with('media_upload_status', $status);
    }

    public function storeForAuthoring(
        StoreMediaAssetRequest $request,
        StoreMediaAssetAction $storeMediaAsset,
    ): JsonResponse {
        $asset = $storeMediaAsset->handle(
            file: $request->file('file'),
            title: $request->input('title'),
            uploadedBy: $request->user(),
        );

        return response()->json(
            $this->authoringState($asset),
            $asset->isProcessing() ? 202 : 200,
        );
    }

    public function authoringStatus(string $assetUuid): JsonResponse
    {
        $asset = MediaAsset::query()
            ->active()
            ->where('uuid', $assetUuid)
            ->firstOrFail();

        return response()->json($this->authoringState($asset));
    }

    public function archive(MediaAsset $mediaAsset): RedirectResponse
    {
        if ($mediaAsset->archived_at === null) {
            $mediaAsset->forceFill(['archived_at' => now()])->save();
        }

        return redirect()
            ->route('crm.media.index')
            ->with('success', 'Media archived.');
    }

    public function restore(MediaAsset $mediaAsset): RedirectResponse
    {
        if ($mediaAsset->archived_at !== null) {
            $mediaAsset->forceFill(['archived_at' => null])->save();
        }

        return redirect()
            ->route('crm.media.index', ['archived' => 1])
            ->with('success', 'Media restored.');
    }

    /** @return array<string, mixed> */
    private function authoringState(MediaAsset $asset): array
    {
        return [
            'asset_uuid' => (string) $asset->uuid,
            'title' => (string) $asset->title,
            'kind' => (string) $asset->kind,
            'ingestion_status' => $asset->ingestionStatus(),
            'ready' => $asset->isReady(),
            'failed' => $asset->hasIngestionFailed(),
            'error' => $asset->hasIngestionFailed()
                ? trim((string) $asset->ingestion_error)
                : null,
            'status_url' => route('crm.media.authoring.status', [
                'assetUuid' => $asset->uuid,
            ]),
        ];
    }
}