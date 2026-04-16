<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DevMessageSink
{
    public function store(string $channel, array $payload): void
    {
        $directory = storage_path('app/dev-messages/' . now()->format('Y-m-d'));

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filename = sprintf(
            '%s_%s_%s.json',
            now()->format('H-i-s-u'),
            $channel,
            Str::uuid()
        );

        File::put(
            $directory . '/' . $filename,
            json_encode([
                'channel' => $channel,
                'created_at' => now()->toIso8601String(),
                'payload' => $payload,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}