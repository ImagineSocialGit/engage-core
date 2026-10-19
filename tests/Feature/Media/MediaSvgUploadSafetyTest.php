<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Actions\StoreMediaAssetAction;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\MediaSvgSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MediaSvgUploadSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['media']);
        config()->set('media.disk', 'public');
        config()->set('media.svg.max_kilobytes', 2048);

        Storage::fake('public');
    }

    public function test_safe_svg_is_stored_as_an_original_only_reusable_image(): void
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <defs>
        <linearGradient id="brand" x1="0" x2="1">
            <stop offset="0" stop-color="#111111"/>
            <stop offset="1" stop-color="#444444"/>
        </linearGradient>
    </defs>
    <circle cx="50" cy="50" r="44" fill="url(#brand)"/>
    <path d="M25 50 L50 25 L75 50 L50 75 Z" fill="#ffffff"/>
</svg>
SVG;

        $upload = $this->svgUpload($svg);

        try {
            $asset = app(StoreMediaAssetAction::class)->handle(
                file: $upload,
                title: 'Brand mark',
            );
        } finally {
            @unlink((string) $upload->getRealPath());
        }

        $this->assertSame(MediaAsset::KIND_IMAGE, $asset->kind);
        $this->assertSame('image/svg+xml', $asset->mime_type);
        $this->assertSame('svg', $asset->extension);
        $this->assertSame(MediaAsset::INGESTION_READY, $asset->ingestionStatus());
        $this->assertNull($asset->perceptual_hash);
        $this->assertFalse($asset->hasProgressiveImageVariants());
        Storage::disk('public')->assertExists($asset->path);
        $this->assertSame($svg, Storage::disk('public')->get($asset->path));
    }

    public function test_svg_validator_rejects_active_or_external_content(): void
    {
        $validator = app(MediaSvgSafetyValidator::class);

        $unsafe = [
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.test/logo.png"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="alert(1)" width="10" height="10"/></svg>',
            '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"/>',
            '<svg xmlns="http://www.w3.org/2000/svg"><style>.x{fill:url(https://example.test/x)}</style></svg>',
        ];

        foreach ($unsafe as $svg) {
            try {
                $validator->assertSafeBytes($svg);
                $this->fail('Unsafe SVG content was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function svgUpload(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'engage-media-svg-');

        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return new UploadedFile(
            path: $path,
            originalName: 'logo.svg',
            mimeType: 'image/svg+xml',
            error: null,
            test: true,
        );
    }
}