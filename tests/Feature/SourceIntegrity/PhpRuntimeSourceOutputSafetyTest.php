<?php

namespace Tests\Feature\SourceIntegrity;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class PhpRuntimeSourceOutputSafetyTest extends TestCase
{
    public function test_first_party_pure_php_runtime_sources_cannot_emit_bytes_outside_php_tags(): void
    {
        $failures = [];

        foreach ($this->runtimePhpFiles() as $file) {
            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                $failures[] = $file->getPathname().' could not be read';

                continue;
            }

            $relative = str_replace(
                base_path().DIRECTORY_SEPARATOR,
                '',
                $file->getPathname(),
            );

            if (! str_starts_with($contents, '<?php')) {
                $failures[] = $relative.' does not begin at byte zero with <?php';

                continue;
            }

            foreach (token_get_all($contents) as $token) {
                if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                    $failures[] = $relative.' contains a PHP closing tag';

                    break;
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function runtimePhpFiles(): array
    {
        $roots = [
            app_path(),
            base_path('bootstrap'),
            base_path('config'),
            base_path('routes'),
            base_path('client'),
        ];

        $files = [];
        $cachePath = base_path('bootstrap/cache').DIRECTORY_SEPARATOR;
        $vendorSegment = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    RecursiveDirectoryIterator::SKIP_DOTS,
                ),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();

                if ($file->getExtension() !== 'php'
                    || str_ends_with($file->getFilename(), '.blade.php')
                    || str_starts_with($path, $cachePath)
                    || str_contains($path, $vendorSegment)
                ) {
                    continue;
                }

                $files[] = $file;
            }
        }

        usort(
            $files,
            static fn (SplFileInfo $left, SplFileInfo $right): int => strcmp(
                $left->getPathname(),
                $right->getPathname(),
            ),
        );

        return $files;
    }
}