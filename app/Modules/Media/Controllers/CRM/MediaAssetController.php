<?php

namespace App\Modules\Media\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Media\Actions\StoreMediaAssetAction;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Requests\StoreMediaAssetRequest;
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
            'subheading' => 'Upload reusable images, video, audio, and files for client communications and other Engage workflows.',
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

        return redirect()
            ->route('crm.media.index')
            ->with(
                'success',
                $reused
                    ? "This file is already in Media — using '{$asset->title}'."
                    : 'Media uploaded.',
            )
            ->with('media_upload_status', $reused ? 'reused' : 'created');
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
}