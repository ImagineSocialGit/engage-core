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

    public function test_story_variant_renders_enabled_configured_stories_and_series_instructor_content(): void
    {
        $this->configureTrustOverridePolicy();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'configured-stories',
            'title' => 'Configured Stories',
        ]);
        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay(),
        ]);

        Config::set('webinars.register.content.instructor', [
            'enabled' => true,
            'eyebrow' => 'Shared Instructor Eyebrow',
            'heading' => 'Shared Instructor Heading',
            'body' => [
                'Shared instructor body.',
            ],
            'credibility' => [
                'Shared instructor credential',
            ],
        ]);
        Config::set('webinars.register.content.trust', [
            'enabled' => true,
            'variant' => 'reviews',
            'headline' => 'Shared Trust Heading',
            'reviews' => [
                [
                    'name' => 'Shared Reviewer',
                    'stars' => '★★★★★',
                    'text' => 'Shared review text.',
                ],
            ],
            'stories' => [],
        ]);
        Config::set("webinars.register.{$series->slug}.content", [
            'instructor' => [
                'eyebrow' => 'Configured Instructor Eyebrow',
                'heading' => 'Configured Instructor Heading',
                'body' => [
                    'Configured instructor introduction.',
                    [
                        [
                            'text' => 'Configured instructor emphasis.',
                            'emphasis' => true,
                        ],
                    ],
                ],
                'credibility' => [
                    'Configured credential one',
                    'Configured credential two',
                ],
            ],
            'trust' => [
                'enabled' => true,
                'variant' => 'stories',
                'headline' => 'Configured Story Heading',
                'body' => 'Configured story introduction.',
                'review_url' => null,
                'reviews' => [],
                'stories' => [
                    [
                        'key' => 'enabled_story',
                        'icon' => '◆',
                        'label' => 'Configured Label',
                        'title' => 'Configured Story Title',
                        'context' => 'Configured story context.',
                        'outcome' => 'Configured story outcome.',
                        'rating' => 5,
                        'quote' => 'Configured story quote.',
                        'is_enabled' => true,
                    ],
                    [
                        'key' => 'disabled_story',
                        'label' => 'Disabled Label',
                        'title' => 'Disabled Story Title',
                        'context' => 'Disabled story context.',
                        'outcome' => 'Disabled story outcome.',
                        'rating' => 5,
                        'quote' => 'Disabled story quote.',
                        'is_enabled' => false,
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response
            ->assertOk()
            ->assertSee('Configured Instructor Eyebrow')
            ->assertSee('Configured Instructor Heading')
            ->assertSee('Configured instructor introduction.')
            ->assertSee('Configured instructor emphasis.')
            ->assertSee('Configured credential one')
            ->assertSee('Configured credential two')
            ->assertSee('Configured Story Heading')
            ->assertSee('Configured story introduction.')
            ->assertSee('Configured Label')
            ->assertSee('Configured Story Title')
            ->assertSee('Configured story context.')
            ->assertSee('Configured story outcome.')
            ->assertSee('Configured story quote.')
            ->assertSee('aria-label="5 out of 5 stars"', false)
            ->assertDontSee('Disabled Story Title')
            ->assertDontSee('Disabled story quote.')
            ->assertDontSee('Shared Reviewer')
            ->assertDontSee('Shared instructor body.');
    }

    public function test_review_variant_preserves_configured_review_cards_and_review_link(): void
    {
        $this->configureTrustOverridePolicy();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'configured-reviews',
            'title' => 'Configured Reviews',
        ]);
        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay(),
        ]);

        Config::set("webinars.register.{$series->slug}.content", [
            'trust' => [
                'enabled' => true,
                'variant' => 'reviews',
                'headline' => 'Configured Review Heading',
                'body' => 'Configured review introduction.',
                'review_label' => 'Open Configured Reviews',
                'review_url' => 'https://example.test/reviews',
                'reviews' => [
                    [
                        'name' => 'Configured Reviewer',
                        'rating' => 4,
                        'text' => 'Configured review text.',
                        'is_enabled' => true,
                    ],
                ],
                'stories' => [
                    [
                        'key' => 'unused_story',
                        'title' => 'Unused Story Title',
                        'is_enabled' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response
            ->assertOk()
            ->assertSee('Configured Review Heading')
            ->assertSee('Configured review introduction.')
            ->assertSee('Configured Reviewer')
            ->assertSee('Configured review text.')
            ->assertSee('Open Configured Reviews')
            ->assertSee('href="https://example.test/reviews"', false)
            ->assertSee('aria-label="4 out of 5 stars"', false)
            ->assertDontSee('Unused Story Title');
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