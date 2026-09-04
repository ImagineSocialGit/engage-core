<?php

namespace Tests\Feature\SetupValidation;

use App\Modules\Media\Providers\MediaModuleServiceProvider;
use App\Modules\Media\Services\ImagePerceptualHasher;
use App\Modules\Media\Validation\MediaSetupValidationContributor;
use Tests\TestCase;

class MediaSetupValidationContributorTest extends TestCase
{
    public function test_enabled_similarity_warns_when_gd_is_unavailable_without_becoming_an_error(): void
    {
        config()->set('media.near_duplicate_images.enabled', true);

        $contributor = new MediaSetupValidationContributor(
            new class extends ImagePerceptualHasher {
                public function available(): bool
                {
                    return false;
                }
            },
        );

        $findings = array_values(iterator_to_array($contributor->findings()));
        $finding = $findings[0] ?? null;

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding->severity);
        $this->assertSame('media.image_similarity_gd_unavailable', $finding->code);
        $this->assertSame('media', $finding->module);
    }

    public function test_disabled_similarity_has_no_gd_warning(): void
    {
        config()->set('media.near_duplicate_images.enabled', false);

        $contributor = new MediaSetupValidationContributor(
            new class extends ImagePerceptualHasher {
                public function available(): bool
                {
                    return false;
                }
            },
        );

        $this->assertSame([], array_values(iterator_to_array($contributor->findings())));
    }

    public function test_media_provider_registers_similarity_setup_validation(): void
    {
        $this->app->register(MediaModuleServiceProvider::class, force: true);

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            iterator_to_array(
                $this->app->tagged('setup.validation_contributors'),
                false,
            ),
        );

        $this->assertContains(MediaSetupValidationContributor::class, $classes);
    }
}