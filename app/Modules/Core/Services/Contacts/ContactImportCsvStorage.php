<?php

namespace App\Modules\Core\Services\Contacts;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Import CSVs live on their configured disk. Seekable temporary streams are
 * reconstructed per request/job; no app-server file is authoritative.
 */
final class ContactImportCsvStorage
{
    public function disk(): string
    {
        $disk = config('contact_imports.storage.disk', 'local');

        if (! is_string($disk) || trim($disk) === '') {
            throw new RuntimeException('The Contact import source disk is not configured.');
        }

        return trim($disk);
    }

    public function store(UploadedFile $file): string
    {
        $disk = $this->disk();
        $path = $file->store('imports', $disk);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The Contact import CSV could not be stored.');
        }

        return $path;
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        return $path !== '' && Storage::disk($disk ?? $this->disk())->exists($path);
    }

    /**
     * Return a seekable, ephemeral copy of a durable CSV. The caller owns the
     * returned stream and must fclose() it, including on exceptions.
     *
     * @return resource
     */
    public function open(string $path, ?string $disk = null)
    {
        $disk = $disk ?? $this->disk();
        $source = Storage::disk($disk)->readStream($path);

        if (! is_resource($source)) {
            throw new RuntimeException('The Contact import CSV could not be read.');
        }

        $temporary = tmpfile();

        if (! is_resource($temporary)) {
            fclose($source);

            throw new RuntimeException('A temporary Contact import CSV stream could not be created.');
        }

        try {
            if (stream_copy_to_stream($source, $temporary) === false
                || rewind($temporary) === false
            ) {
                throw new RuntimeException('The Contact import CSV could not be staged for reading.');
            }
        } catch (\Throwable $exception) {
            fclose($temporary);
            throw $exception;
        } finally {
            fclose($source);
        }

        return $temporary;
    }

    public function delete(string $path, ?string $disk = null): void
    {
        if ($path !== '') {
            Storage::disk($disk ?? $this->disk())->delete($path);
        }
    }
}