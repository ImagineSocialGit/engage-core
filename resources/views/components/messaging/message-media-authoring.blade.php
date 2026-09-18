@if(($presentation['available'] ?? false) === true)
    <x-ui.message-media-editor
        :presentation="$presentation"
        :field-prefix="$fieldPrefix"
        :visible-bind="$visibleBind"
        :selected-asset-uuid="$selectedAssetUuid"
        :selected-poster-asset-uuid="$selectedPosterAssetUuid"
        :selected-title="$selectedTitle"
        :selected-size="$selectedSize"
        :asset-model="$assetModel"
        :poster-model="$posterModel"
        :title-model="$titleModel"
        :size-model="$sizeModel"
    />
@endif

<x-ui.message-attachment-editor
    :options="$attachmentPresentation['options']"
    :selected="$attachmentPresentation['selected']"
    :field-prefix="$fieldPrefix"
    :visible-bind="$visibleBind"
    :selection-model="$attachmentModel"
    :upload-sources="$attachmentPresentation['upload_sources']"
/>