<?php

namespace App\Modules\Messaging\View\Components;

use App\Modules\Messaging\Services\MessageMediaAuthoringService;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class MessageMediaAuthoring extends Component
{
    /** @var array<string, mixed> */
    public array $presentation;

    public string $selectedAssetUuid;

    public string $selectedPosterAssetUuid;

    public string $selectedTitle;

    /**
     * @param array<string, mixed> $currentMedia
     */
    public function __construct(
        MessageMediaAuthoringService $mediaAuthoring,
        public array $currentMedia = [],
        public string $fieldPrefix = '',
        public ?string $visibleBind = null,
        public bool $failed = false,
        public ?string $assetModel = null,
        public ?string $posterModel = null,
        public ?string $titleModel = null,
    ) {
        $this->presentation = $mediaAuthoring->presentation([$currentMedia]);

        $currentAssetUuid = is_string($currentMedia['asset_uuid'] ?? null)
            ? trim($currentMedia['asset_uuid'])
            : '';
        $currentPosterAssetUuid = is_string($currentMedia['poster_asset_uuid'] ?? null)
            ? trim($currentMedia['poster_asset_uuid'])
            : '';

        $dotPrefix = $fieldPrefix !== '' ? $fieldPrefix.'.' : '';

        $this->selectedAssetUuid = $failed
            ? trim((string) old($dotPrefix.'media_asset_uuid', $currentAssetUuid))
            : $currentAssetUuid;
        $this->selectedPosterAssetUuid = $failed
            ? trim((string) old($dotPrefix.'media_poster_asset_uuid', $currentPosterAssetUuid))
            : $currentPosterAssetUuid;
        $this->selectedTitle = $failed
            ? trim((string) old($dotPrefix.'media_title', ''))
            : '';
    }

    public function render(): View
    {
        return view('components.messaging.message-media-authoring');
    }
}