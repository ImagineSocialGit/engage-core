<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

final class MediaVideoPosterGenerator
{
    public function available(): bool
    {
        try {
            $process = new Process([$this->binary(), '-version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function generate(MediaAsset $asset): bool
    {
        if (! (bool) config('media.video_posters.enabled', true)
            || $asset->kind !== MediaAsset::KIND_VIDEO
            || $asset->videoPosterUrl() !== null
            || ! $this->available()) {
            return false;
        }

        $disk = trim((string) $asset->disk);
        $path = trim((string) $asset->path);
        if ($disk === '' || $path === '') {
            return false;
        }

        $input = tempnam(sys_get_temp_dir(), 'media_video_');
        $output = tempnam(sys_get_temp_dir(), 'media_poster_');
        if (! is_string($input) || ! is_string($output)) {
            if (is_string($input)) {
                @unlink($input);
            }
            if (is_string($output)) {
                @unlink($output);
            }
            throw new RuntimeException('Unable to allocate temporary files for video poster generation.');
        }

        try {
            $source = Storage::disk($disk)->readStream($path);
            if (! is_resource($source)) {
                throw new RuntimeException("Video [{$asset->uuid}] could not be streamed for poster generation.");
            }
            $target = fopen($input, 'wb');
            if (! is_resource($target)) {
                fclose($source);
                throw new RuntimeException('Temporary video input could not be opened.');
            }
            try {
                if (stream_copy_to_stream($source, $target) === false) {
                    throw new RuntimeException('Video download was interrupted during poster generation.');
                }
            } finally {
                fclose($source);
                fclose($target);
            }

            if (! $this->render($input, $output, '0.5')
                && ! $this->render($input, $output, '0')) {
                return false;
            }
            $dimensions = @getimagesize($output);
            if (! is_array($dimensions) || (int) ($dimensions[0] ?? 0) < 1) {
                return false;
            }

            $posterPath = trim(dirname($path), '/').'/video-poster.jpg';
            $stream = fopen($output, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('Generated video poster could not be read.');
            }
            try {
                if (! Storage::disk($disk)->put($posterPath, $stream, ['visibility' => MediaAsset::VISIBILITY_PUBLIC])) {
                    throw new RuntimeException('Generated video poster could not be stored.');
                }
            } finally {
                fclose($stream);
            }

            $meta = is_array($asset->meta) ? $asset->meta : [];
            $meta['video_poster'] = [
                'version' => 1,
                'path' => $posterPath,
                'generated_at' => now()->toIso8601String(),
            ];
            $asset->forceFill(['meta' => $meta])->save();

            return true;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    private function render(string $input, string $output, string $seconds): bool
    {
        $process = new Process([
            $this->binary(), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', $seconds, '-i', $input, '-frames:v', '1',
            '-vf', 'scale=1280:-2:force_original_aspect_ratio=decrease',
            '-c:v', 'mjpeg', '-q:v', '3', '-f', 'image2', $output,
        ]);
        $process->setTimeout(max(30, (int) config('media.video_posters.timeout_seconds', 120)));
        $process->run();

        clearstatcache(true, $output);

        return $process->isSuccessful() && filesize($output) > 0;
    }

    private function binary(): string
    {
        return trim((string) config('media.video_posters.ffmpeg_binary', 'ffmpeg')) ?: 'ffmpeg';
    }
}