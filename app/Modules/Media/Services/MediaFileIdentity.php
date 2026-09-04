<?php

namespace App\Modules\Media\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

final class MediaFileIdentity
{
    public function checksum(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $checksum = is_string($path) && $path !== ''
            ? hash_file('sha256', $path)
            : false;

        if (! is_string($checksum) || $checksum === '') {
            throw new RuntimeException('The uploaded media checksum could not be calculated.');
        }

        return strtolower($checksum);
    }
}