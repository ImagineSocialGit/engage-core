<?php

namespace App\Modules\Messaging\Requests\Concerns;

use App\Modules\Messaging\Services\MessageMediaAuthoringService;
use Illuminate\Http\UploadedFile;

trait InteractsWithMessageMediaAuthoring
{
    /** @return array<string, mixed> */
    protected function messageMediaRules(string $prefix = ''): array
    {
        return app(MessageMediaAuthoringService::class)->validationRules($prefix);
    }

    public function hasMessageMediaSubmission(string $prefix = ''): bool
    {
        $presentKey = $this->messageMediaKey($prefix, 'media_present');
        $uploadKey = $this->messageMediaKey($prefix, 'media_upload');

        return $this->hasFile($uploadKey)
            || filter_var(
                data_get($this->all(), $presentKey),
                FILTER_VALIDATE_BOOLEAN,
            );
    }

    public function messageMediaAssetUuid(string $prefix = ''): ?string
    {
        return $this->nullableMessageMediaString($prefix, 'media_asset_uuid');
    }

    public function messageMediaPosterAssetUuid(string $prefix = ''): ?string
    {
        return $this->nullableMessageMediaString($prefix, 'media_poster_asset_uuid');
    }

    public function messageMediaTitle(string $prefix = ''): ?string
    {
        return $this->nullableMessageMediaString($prefix, 'media_title');
    }

    public function messageMediaUpload(string $prefix = ''): ?UploadedFile
    {
        $file = $this->file($this->messageMediaKey($prefix, 'media_upload'));

        return $file instanceof UploadedFile ? $file : null;
    }

    private function nullableMessageMediaString(string $prefix, string $field): ?string
    {
        $value = data_get(
            $this->validated(),
            $this->messageMediaKey($prefix, $field),
        );

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function messageMediaKey(string $prefix, string $field): string
    {
        return $prefix === '' ? $field : $prefix.'.'.$field;
    }
}