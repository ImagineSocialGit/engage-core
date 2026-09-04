@if(($presentation['available'] ?? false) === true)
    <x-ui.message-media-editor
        :presentation="$presentation"
        :field-prefix="$fieldPrefix"
        :visible-bind="$visibleBind"
        :selected-asset-uuid="$selectedAssetUuid"
        :selected-poster-asset-uuid="$selectedPosterAssetUuid"
        :selected-title="$selectedTitle"
        :asset-model="$assetModel"
        :poster-model="$posterModel"
        :title-model="$titleModel"
    />
@endif