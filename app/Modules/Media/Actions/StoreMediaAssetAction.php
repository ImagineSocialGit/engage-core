<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\MediaAsset;
use App\Support\Modules\ModuleManager;
use App\Modules\Media\Services\MediaUploadPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StoreMediaAssetAction
{
    public function __construct(
        private readonly MediaUploadPolicy $uploadPolicy,
    ) {}

    public function handle(
        UploadedFile $file,
        ?string $title = null,
        ?Model $uploadedBy = null,
    ): MediaAsset {
        $mimeType = $this->uploadPolicy->effectiveMimeType($file);

        if ($mimeType === null) {
            throw new RuntimeException('The uploaded media type is not supported.');
        }

        $disk = $this->disk();
        $uuid = (string) Str::uuid();
        $extension = $this->extension($file);
        $filename = $uuid.($extension !== null ? '.'.$extension : '');
        $directory = trim((string) config('media.directory', 'media'), '/');
        $directory = ($directory !== '' ? $directory.'/' : '').$uuid;
        $checksum = hash_file('sha256', $file->getRealPath());

        if (! is_string($checksum) || $checksum === '') {
            throw new RuntimeException('The uploaded media checksum could not be calculated.');
        }

        $storedPath = Storage::disk($disk)->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => MediaAsset::VISIBILITY_PUBLIC],
        );

        if (! is_string($storedPath) || trim($storedPath) === '') {
            throw new RuntimeException("Media upload to disk [{$disk}] failed.");
        }

        try {
            return MediaAsset::query()->create([
                'uuid' => $uuid,
                'uploaded_by_type' => $uploadedBy?->getMorphClass(),
                'uploaded_by_id' => $uploadedBy?->getKey(),
                'title' => $this->title($title, $file),
                'kind' => $this->uploadPolicy->kindForMimeType($mimeType),
                'disk' => $disk,
                'path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size_bytes' => is_int($file->getSize()) ? $file->getSize() : null,
                'checksum_sha256' => $checksum,
                'visibility' => MediaAsset::VISIBILITY_PUBLIC,
                'source' => 'crm',
                'meta' => null,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);

            throw $exception;
        }
    }

    private function disk(): string
    {
        $configured = config('media.disk');
        $disk = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : trim((string) config('filesystems.default', 'local'));

        if ($disk === '') {
            throw new RuntimeException('Media storage disk is not configured.');
        }

        return $disk;
    }

    private function extension(UploadedFile $file): ?string
    {
        $extension = $file->guessExtension();

        if (! is_string($extension) || trim($extension) === '') {
            $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        }

        $extension = strtolower(trim((string) $extension));

        return preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1
            ? $extension
            : null;
    }

    private function title(?string $title, UploadedFile $file): string
    {
        $title = is_string($title) ? trim($title) : '';

        if ($title === '') {
            $title = trim((string) pathinfo(
                $file->getClientOriginalName(),
                PATHINFO_FILENAME,
            ));
        }

        if ($title === '') {
            $title = 'Media asset';
        }

        return Str::limit($title, 255, '');
    }
}