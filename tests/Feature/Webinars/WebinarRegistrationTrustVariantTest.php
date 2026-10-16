<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarRegistrationTrustVariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_series_trust_and_instructor_lists_replace_shared_lists_atomically(): void
    {
        Config::set('webinars.content', []);
        Config::set('webinars.register.content', [
            'series_overrides' => [
                'landing' => [
                    'instructor' => true,
                    'trust' => true,
                ],
                'registration' => [],
            ],
            'instructor' => [
                'body' => [
                    'Shared instructor paragraph one.',
                    'Shared instructor paragraph two.',
                ],
                'credibility' => [
                    'Shared credential one',
                    'Shared credential two',
                ],
            ],
            'trust' => [
                'variant' => 'reviews',
                'reviews' => [
                    [
                        'name' => 'Shared Reviewer One',
                        'text' => 'Shared review one.',
                    ],
                    [
                        'name' => 'Shared Reviewer Two',
                        'text' => 'Shared review two.',
                    ],
                ],
                'stories' => [
                    [
                        'key' => 'shared_story',
                        'title' => 'Shared Story',
                    ],
                ],
            ],
        ]);
        Config::set('webinars.register.series-story.content', [
            'instructor' => [
                'body' => [
                    'Series instructor paragraph.',
                ],
                'credibility' => [
                    'Series credential',
                ],
            ],
            'trust' => [
                'variant' => 'stories',
                'reviews' => [],
                'stories' => [
                    [
                        'key' => 'series_story',
                        'title' => 'Series Story',
                    ],
                ],
            ],
        ]);

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'series-story',
        );
        $landing = $content['landing'];

        $this->assertSame(
            ['Series instructor paragraph.'],
            data_get($landing, 'instructor.body'),
        );
        $this->assertSame(
            ['Series credential'],
            data_get($landing, 'instructor.credibility'),
        );
        $this->assertSame([], data_get($landing, 'trust.reviews'));
        $this->assertSame(
            [
                [
                    'key' => 'series_story',
                    'title' => 'Series Story',
                ],
            ],
            data_get($landing, 'trust.stories'),
        );
    }


    private function configureTrustOverridePolicy(): void
    {
        Config::set('webinars.register.content.series_overrides', [
            'landing' => [
                'instructor' => true,
                'trust' => true,
            ],
            'registration' => [],
        ]);
    }
}