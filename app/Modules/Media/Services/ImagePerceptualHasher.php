<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Data\ImagePerceptualFingerprint;
use Illuminate\Http\UploadedFile;

class ImagePerceptualHasher
{
    public const ALGORITHM = 'dhash64_v1';

    private const SAMPLE_WIDTH = 9;
    private const SAMPLE_HEIGHT = 8;

    /** @var array<int, int> */
    private const NIBBLE_BIT_COUNTS = [
        0, 1, 1, 2,
        1, 2, 2, 3,
        1, 2, 2, 3,
        2, 3, 3, 4,
    ];

    public function available(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('imagecolorat');
    }

    public function fingerprint(UploadedFile $file): ?ImagePerceptualFingerprint
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            return null;
        }

        $bytes = file_get_contents($path);

        return is_string($bytes)
            ? $this->fingerprintBytes($bytes)
            : null;
    }

    public function fingerprintBytes(string $bytes): ?ImagePerceptualFingerprint
    {
        if (! $this->available() || $bytes === '') {
            return null;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width < 1 || $height < 1) {
            return null;
        }

        $sample = imagecreatetruecolor(self::SAMPLE_WIDTH, self::SAMPLE_HEIGHT);

        if ($sample === false) {
            return null;
        }

        if (! imagecopyresampled(
            $sample,
            $source,
            0,
            0,
            0,
            0,
            self::SAMPLE_WIDTH,
            self::SAMPLE_HEIGHT,
            $width,
            $height,
        )) {
            return null;
        }

        $bits = '';

        for ($y = 0; $y < self::SAMPLE_HEIGHT; $y++) {
            for ($x = 0; $x < self::SAMPLE_WIDTH - 1; $x++) {
                $left = $this->luminance(imagecolorat($sample, $x, $y));
                $right = $this->luminance(imagecolorat($sample, $x + 1, $y));
                $bits .= $left > $right ? '1' : '0';
            }
        }

        return new ImagePerceptualFingerprint(
            hash: $this->bitsToHex($bits),
            algorithm: self::ALGORITHM,
            width: $width,
            height: $height,
        );
    }

    public function hammingDistance(string $left, string $right): ?int
    {
        $left = strtolower(trim($left));
        $right = strtolower(trim($right));

        if (preg_match('/\A[0-9a-f]{16}\z/', $left) !== 1
            || preg_match('/\A[0-9a-f]{16}\z/', $right) !== 1
        ) {
            return null;
        }

        $distance = 0;

        for ($index = 0; $index < 16; $index++) {
            $xor = hexdec($left[$index]) ^ hexdec($right[$index]);
            $distance += self::NIBBLE_BIT_COUNTS[$xor];
        }

        return $distance;
    }

    private function luminance(int $color): int
    {
        $red = ($color >> 16) & 0xff;
        $green = ($color >> 8) & 0xff;
        $blue = $color & 0xff;

        return (int) round(
            ($red * 299 + $green * 587 + $blue * 114) / 1000,
        );
    }

    private function bitsToHex(string $bits): string
    {
        $hex = '';

        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return str_pad($hex, 16, '0', STR_PAD_LEFT);
    }
}