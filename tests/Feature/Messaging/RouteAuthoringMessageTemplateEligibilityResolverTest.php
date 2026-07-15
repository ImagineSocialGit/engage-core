<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\RouteAuthoringMessageTemplateEligibilityResolver;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteAuthoringMessageTemplateEligibilityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_only_templates_safe_for_direct_route_authoring(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
            'messaging',
        ]);

        $generic = MessageTemplatePreset::factory()->create([
            'name' => 'General Follow-Up',
            'purpose' => 'transactional',
            'scope' => 'general',
            'dispatch_keys' => ['route_point_due'],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [
                'route_authoring' => [
                    'eligible' => true,
                ],
            ],
        ]);

        MessageTemplateCatalogEntry::factory()->create([
            'message_template_preset_id' => $generic->getKey(),
            'module_key' => 'messaging',
            'surface' => null,
            'usage_type' => 'general_follow_up',
            'is_active' => true,
        ]);

        $webinar = MessageTemplatePreset::factory()->create([
            'name' => 'Webinar Reminder',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'dispatch_keys' => ['registration_created'],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        MessageTemplateCatalogEntry::factory()->create([
            'message_template_preset_id' => $webinar->getKey(),
            'module_key' => 'webinars',
            'surface' => 'webinar_registrations',
            'usage_type' => 'webinar_reminder',
            'is_active' => true,
        ]);

        $campaign = MessageTemplatePreset::factory()->create([
            'name' => 'Campaign Step',
            'purpose' => 'marketing',
            'scope' => 'nurture',
            'dispatch_keys' => ['campaign_step_due'],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        MessageTemplateCatalogEntry::factory()->create([
            'message_template_preset_id' => $campaign->getKey(),
            'module_key' => 'campaigns',
            'surface' => 'campaigns',
            'usage_type' => 'campaign_step',
            'is_active' => true,
        ]);

        $eligible = app(RouteAuthoringMessageTemplateEligibilityResolver::class)->eligiblePresets();

        $this->assertSame([$generic->getKey()], $eligible->pluck('id')->all());
    }

    public function test_internal_template_is_never_route_eligible_even_with_explicit_opt_in(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
            'messaging',
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Internal Notification',
            'purpose' => 'internal',
            'scope' => 'internal_notifications',
            'dispatch_keys' => ['internal_notification'],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [
                'route_authoring' => [
                    'eligible' => true,
                ],
            ],
        ]);

        $eligible = app(RouteAuthoringMessageTemplateEligibilityResolver::class)->eligiblePresets();

        $this->assertFalse($eligible->contains(fn (MessageTemplatePreset $candidate): bool => $candidate->is($preset)));
    }
}
