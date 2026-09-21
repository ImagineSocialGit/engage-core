<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Models\MediaAsset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class MediaVideoIngestor
{
    public function available(): bool
    {
        return $this->binaryAvailable($this->ffmpegBinary())
            && $this->binaryAvailable($this->ffprobeBinary());
    }

    public function ingest(MediaAsset $asset): MediaAsset
    {
        if ($asset->kind !== MediaAsset::KIND_VIDEO) {
            throw new RuntimeException('Only video Media assets can enter video ingestion.');
        }

        if (! $asset->isProcessing()) {
            return $asset;
        }

        if (! (bool) config('media.video_ingestion.enabled', true)) {
            throw new RuntimeException('Video ingestion is disabled.');
        }

        if (! $this->available()) {
            throw new RuntimeException('FFmpeg and FFprobe are required for video ingestion.');
        }

        $disk = trim((string) $asset->disk);
        $sourcePath = trim((string) $asset->path);

        if ($disk === '' || $sourcePath === '') {
            throw new RuntimeException('The video ingestion source is unavailable.');
        }

        $workspace = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'engage-media-'.trim((string) $asset->uuid).'-'.Str::random(10);

        if (! File::makeDirectory($workspace, 0700, true)) {
            throw new RuntimeException('Unable to create the temporary video ingestion workspace.');
        }

        $sourceExtension = is_string($asset->extension) && trim($asset->extension) !== ''
            ? strtolower(trim($asset->extension))
            : 'bin';
        $localSource = $workspace.DIRECTORY_SEPARATOR.'source.'.$sourceExtension;
        $localVideo = $workspace.DIRECTORY_SEPARATOR.'canonical.mp4';
        $localPoster = $workspace.DIRECTORY_SEPARATOR.'poster.jpg';
        $canonicalPath = $this->canonicalPath($asset);
        $posterPath = $this->posterPath($asset);
        $canonicalStored = false;
        $posterStored = false;

        try {
            $this->downloadSource($disk, $sourcePath, $localSource);

            $sourceProbe = $this->probe($localSource);

            if ($this->canRemux($sourceProbe)) {
                $this->remux($localSource, $localVideo);
            } else {
                $this->transcode($localSource, $localVideo);
            }

            $canonicalProbe = $this->probe($localVideo);
            $this->assertCanonical($canonicalProbe);
            $this->renderPoster($localVideo, $localPoster);

            $this->storePublicFile($disk, $canonicalPath, $localVideo);
            $canonicalStored = true;

            $this->storePublicFile($disk, $posterPath, $localPoster);
            $posterStored = true;

            $size = filesize($localVideo);

            if (! is_int($size) || $size < 1) {
                throw new RuntimeException('The normalized video has an invalid file size.');
            }

            $meta = is_array($asset->meta) ? $asset->meta : [];
            $meta['video_ingestion'] = array_replace(
                is_array($meta['video_ingestion'] ?? null)
                    ? $meta['video_ingestion']
                    : [],
                [
                    'version' => 1,
                    'source_mime_type' => is_string($asset->mime_type)
                        ? $asset->mime_type
                        : null,
                    'source_extension' => is_string($asset->extension)
                        ? $asset->extension
                        : null,
                    'source_size_bytes' => $asset->size_bytes,
                    'source_probe' => $this->probeSummary($sourceProbe),
                    'canonical_probe' => $this->probeSummary($canonicalProbe),
                    'mode' => $this->canRemux($sourceProbe) ? 'remux' : 'transcode',
                    'processed_at' => now()->toIso8601String(),
                ],
            );
            $meta['video_poster'] = [
                'version' => 1,
                'path' => $posterPath,
                'generated_at' => now()->toIso8601String(),
            ];

            $asset->forceFill([
                'path' => $canonicalPath,
                'mime_type' => 'video/mp4',
                'extension' => 'mp4',
                'size_bytes' => $size,
                'ingestion_status' => MediaAsset::INGESTION_READY,
                'ingestion_error' => null,
                'ingested_at' => now(),
                'meta' => $meta,
            ])->save();

            try {
                Storage::disk($disk)->delete($sourcePath);
            } catch (Throwable $exception) {
                report($exception);
            }

            return $asset->refresh();
        } catch (Throwable $exception) {
            if ($canonicalStored) {
                $this->deleteQuietly($disk, $canonicalPath);
            }

            if ($posterStored) {
                $this->deleteQuietly($disk, $posterPath);
            }

            throw $exception;
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    /** @return array<string, mixed> */
    private function probe(string $path): array
    {
        $process = new Process([
            $this->ffprobeBinary(),
            '-v', 'error',
            '-show_entries', 'format=format_name,duration',
            '-show_entries', 'stream=index,codec_type,codec_name,profile,width,height,pix_fmt',
            '-of', 'json',
            $path,
        ]);
        $process->setTimeout($this->probeTimeout());
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Video inspection failed: '.$this->processError($process),
            );
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Video inspection returned invalid metadata.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $probe */
    private function canRemux(array $probe): bool
    {
        $streams = is_array($probe['streams'] ?? null)
            ? $probe['streams']
            : [];

        $video = collect($streams)->first(
            fn (mixed $stream): bool => is_array($stream)
                && ($stream['codec_type'] ?? null) === 'video',
        );

        if (! is_array($video)
            || ($video['codec_name'] ?? null) !== 'h264'
            || ($video['pix_fmt'] ?? null) !== 'yuv420p'
        ) {
            return false;
        }

        foreach ($streams as $stream) {
            if (! is_array($stream) || ($stream['codec_type'] ?? null) !== 'audio') {
                continue;
            }

            if (($stream['codec_name'] ?? null) !== 'aac') {
                return false;
            }
        }

        return true;
    }

    private function remux(string $source, string $output): void
    {
        $this->runFfmpeg([
            '-i', $source,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-c', 'copy',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $output,
        ]);
    }

    private function transcode(string $source, string $output): void
    {
        $this->runFfmpeg([
            '-i', $source,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-c:v', 'libx264',
            '-preset', trim((string) config('media.video_ingestion.preset', 'medium')) ?: 'medium',
            '-crf', (string) max(0, min(51, (int) config('media.video_ingestion.crf', 23))),
            '-pix_fmt', 'yuv420p',
            '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            '-c:a', 'aac',
            '-b:a', trim((string) config('media.video_ingestion.audio_bitrate', '128k')) ?: '128k',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $output,
        ]);
    }

    /** @param array<string, mixed> $probe */
    private function assertCanonical(array $probe): void
    {
        $streams = is_array($probe['streams'] ?? null)
            ? $probe['streams']
            : [];

        $video = collect($streams)->first(
            fn (mixed $stream): bool => is_array($stream)
                && ($stream['codec_type'] ?? null) === 'video',
        );

        if (! is_array($video)
            || ($video['codec_name'] ?? null) !== 'h264'
            || ($video['pix_fmt'] ?? null) !== 'yuv420p'
        ) {
            throw new RuntimeException('Normalized video did not satisfy the H.264/yuv420p playback contract.');
        }

        foreach ($streams as $stream) {
            if (! is_array($stream) || ($stream['codec_type'] ?? null) !== 'audio') {
                continue;
            }

            if (($stream['codec_name'] ?? null) !== 'aac') {
                throw new RuntimeException('Normalized video audio did not satisfy the AAC playback contract.');
            }
        }
    }

    private function renderPoster(string $video, string $poster): void
    {
        if ($this->renderPosterAt($video, $poster, '0.5')) {
            return;
        }

        if ($this->renderPosterAt($video, $poster, '0')) {
            return;
        }

        throw new RuntimeException('A poster image could not be generated from the normalized video.');
    }

    private function renderPosterAt(string $video, string $poster, string $seconds): bool
    {
        $process = new Process([
            $this->ffmpegBinary(),
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', $seconds,
            '-i', $video,
            '-frames:v', '1',
            '-vf', 'scale=1280:-2:force_original_aspect_ratio=decrease',
            '-c:v', 'mjpeg',
            '-q:v', '3',
            '-f', 'image2',
            $poster,
        ]);
        $process->setTimeout($this->probeTimeout());
        $process->run();

        clearstatcache(true, $poster);

        return $process->isSuccessful()
            && is_file($poster)
            && filesize($poster) > 0;
    }

    /** @param array<int, string> $arguments */
    private function runFfmpeg(array $arguments): void
    {
        $process = new Process([
            $this->ffmpegBinary(),
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            ...$arguments,
        ]);
        $process->setTimeout($this->ingestionTimeout());
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Video normalization failed: '.$this->processError($process),
            );
        }
    }

    private function downloadSource(string $disk, string $path, string $target): void
    {
        $source = Storage::disk($disk)->readStream($path);

        if (! is_resource($source)) {
            throw new RuntimeException('The uploaded video could not be opened for ingestion.');
        }

        $destination = fopen($target, 'wb');

        if (! is_resource($destination)) {
            fclose($source);

            throw new RuntimeException('The temporary video source could not be created.');
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException('The uploaded video could not be copied into the ingestion workspace.');
            }
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    private function storePublicFile(string $disk, string $path, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');

        if (! is_resource($stream)) {
            throw new RuntimeException('A generated video artifact could not be opened.');
        }

        try {
            if (! Storage::disk($disk)->put(
                $path,
                $stream,
                ['visibility' => MediaAsset::VISIBILITY_PUBLIC],
            )) {
                throw new RuntimeException("Generated video artifact [{$path}] could not be stored.");
            }
        } finally {
            fclose($stream);
        }
    }

    private function canonicalPath(MediaAsset $asset): string
    {
        $directory = trim((string) config('media.directory', 'media'), '/');
        $directory = $directory !== '' ? $directory.'/' : '';

        return $directory.$asset->uuid.'/'.$asset->uuid.'.mp4';
    }

    private function posterPath(MediaAsset $asset): string
    {
        return dirname($this->canonicalPath($asset)).'/video-poster.jpg';
    }

    /** @param array<string, mixed> $probe
     *  @return array<string, mixed>
     */
    private function probeSummary(array $probe): array
    {
        return [
            'format_name' => data_get($probe, 'format.format_name'),
            'duration' => data_get($probe, 'format.duration'),
            'streams' => array_values(array_map(
                static fn (mixed $stream): array => is_array($stream)
                    ? array_filter([
                        'codec_type' => $stream['codec_type'] ?? null,
                        'codec_name' => $stream['codec_name'] ?? null,
                        'profile' => $stream['profile'] ?? null,
                        'width' => $stream['width'] ?? null,
                        'height' => $stream['height'] ?? null,
                        'pix_fmt' => $stream['pix_fmt'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null)
                    : [],
                is_array($probe['streams'] ?? null) ? $probe['streams'] : [],
            )),
        ];
    }

    private function binaryAvailable(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function processError(Process $process): string
    {
        $error = trim($process->getErrorOutput());

        if ($error === '') {
            $error = trim($process->getOutput());
        }

        return Str::limit($error !== '' ? $error : 'unknown FFmpeg error', 2000, '');
    }

    private function deleteQuietly(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function ffmpegBinary(): string
    {
        return trim((string) config('media.video_ingestion.ffmpeg_binary', 'ffmpeg')) ?: 'ffmpeg';
    }

    private function ffprobeBinary(): string
    {
        return trim((string) config('media.video_ingestion.ffprobe_binary', 'ffprobe')) ?: 'ffprobe';
    }

    private function ingestionTimeout(): int
    {
        return max(60, (int) config('media.video_ingestion.timeout_seconds', 1500));
    }

    private function probeTimeout(): int
    {
        return max(15, (int) config('media.video_ingestion.probe_timeout_seconds', 60));
    }
}