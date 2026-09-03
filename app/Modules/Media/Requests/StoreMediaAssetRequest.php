<?php

namespace App\Modules\Media\Requests;

use App\Modules\Media\Services\MediaUploadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class StoreMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $uploadPolicy = app(MediaUploadPolicy::class);

        return [
            'title' => ['nullable', 'string', 'max:255'],
            'file' => [
                'required',
                'file',
                'max:'.max(1, (int) config('media.max_upload_kilobytes', 262144)),
                static function (string $attribute, mixed $value, \Closure $fail) use ($uploadPolicy): void {
                    if (! $value instanceof UploadedFile
                        || $uploadPolicy->effectiveMimeType($value) === null
                    ) {
                        $fail('The uploaded file type is not supported.');
                    }
                },
            ],
        ];
    }
}