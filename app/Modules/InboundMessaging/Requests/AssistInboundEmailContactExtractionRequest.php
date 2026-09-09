<?php

namespace App\Modules\InboundMessaging\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class AssistInboundEmailContactExtractionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sample_eml' => [
                'required',
                'file',
                'max:2048',
                'extensions:eml',
            ],
        ];
    }

    public function sample(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('sample_eml');

        return $file;
    }

    public function contents(): string
    {
        $path = $this->sample()->getRealPath();
        $contents = is_string($path) && $path !== ''
            ? file_get_contents($path)
            : false;

        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([
                'sample_eml' => 'The uploaded .eml file could not be read.',
            ]);
        }

        return $contents;
    }
}