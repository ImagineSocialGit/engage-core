<?php

namespace App\Modules\Messaging\View\Components;

use App\Modules\Messaging\Services\MessageAttachmentAuthoringService;
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

    public string $selectedSize;

    /** @var array<string, mixed> */
    public array $attachmentPresentation;

    /**
     * @param array<string, mixed> $currentMedia
     */
    public function __construct(
        MessageMediaAuthoringService $mediaAuthoring,
        MessageAttachmentAuthoringService $attachmentAuthoring,
        public array $currentMedia = [],
        public array $currentAttachments = [],
        public ?int $contactId = null,
        public string $fieldPrefix = '',
        public ?string $visibleBind = null,
        public bool $failed = false,
        public ?string $assetModel = null,
        public ?string $posterModel = null,
        public ?string $titleModel = null,
        public ?string $sizeModel = null,
        public ?string $attachmentModel = null,
    ) {
        $this->presentation = $mediaAuthoring->presentation([$currentMedia]);
        $this->attachmentPresentation = $attachmentAuthoring->presentation($currentAttachments, $contactId);

        $currentAssetUuid = is_string($currentMedia['asset_uuid'] ?? null)
            ? trim($currentMedia['asset_uuid'])
            : '';
        $currentPosterAssetUuid = is_string($currentMedia['poster_asset_uuid'] ?? null)
            ? trim($currentMedia['poster_asset_uuid'])
            : '';

        $dotPrefix = $fieldPrefix !== '' ? $fieldPrefix.'.' : '';

        if ($failed) {
            $old = old($dotPrefix.'attachment_refs');
            if (is_array($old)) {
                $this->attachmentPresentation['selected'] = array_values(array_filter($old, 'is_string'));
            }
        }

        $this->selectedAssetUuid = $failed
            ? trim((string) old($dotPrefix.'media_asset_uuid', $currentAssetUuid))
            : $currentAssetUuid;
        $this->selectedPosterAssetUuid = $failed
            ? trim((string) old($dotPrefix.'media_poster_asset_uuid', $currentPosterAssetUuid))
            : $currentPosterAssetUuid;
        $currentSize = is_string($currentMedia['display_size'] ?? null)
            ? $currentMedia['display_size']
            : 'full';
        $this->selectedSize = $failed
            ? (string) old($dotPrefix.'media_size', $currentSize)
            : $currentSize;
        $this->selectedTitle = $failed
            ? trim((string) old($dotPrefix.'media_title', ''))
            : '';
    }

    public function render(): View
    {
        return view('components.messaging.message-media-authoring');
    }
}