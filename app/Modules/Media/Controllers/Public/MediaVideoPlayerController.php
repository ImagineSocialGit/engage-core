<?php

namespace App\Modules\Media\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\View\View;

final class MediaVideoPlayerController extends Controller
{
    public function __invoke(string $assetUuid): View
    {
        $asset = MediaAsset::query()
            ->where('uuid', $assetUuid)
            ->where('kind', MediaAsset::KIND_VIDEO)
            ->where('visibility', MediaAsset::VISIBILITY_PUBLIC)
            ->firstOrFail();
        $url = $asset->publicUrl();
        abort_unless(is_string($url) && preg_match('~^https?://~i', $url) === 1, 404);

        return view('media.video-player', [
            'title' => $asset->title,
            'videoUrl' => $url,
            'posterUrl' => $asset->videoPosterUrl(),
            'mimeType' => $asset->mime_type,
        ]);
    }
}