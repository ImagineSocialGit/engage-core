<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ReusableMessageTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateReusableMessageTemplateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_catalogued_versioned_reusable_message_from_calling_surface_context(): void
    {
        $user = User::factory()->create();

        $preset = app(CreateReusableMessageTemplateAction::class)->handle(
            name: 'Birthday Check-In',
            channel: 'email',
            payload: [
                'subject' => 'Happy birthday',
                'body' => 'Hope you have a great birthday.'
            ],
            context: new ReusableMessageTemplateAuthoringContext(
                contextKey: 'campaign_annual_touch',
                purpose: 'marketing',
                scope: 'mortgage_past_client',
                dispatchKey: 'campaign_touch_due',
                messageType: 'campaign_annual_touch',
                payloadClass: EmailPayload::class,
                queue: 'marketing',
                moduleKey: 'campaigns',
                moduleLabel: 'Campaigns',
                surface: 'campaigns',
                groupKey: 'campaign:past_client_nurture:annual_touches:email',
                groupLabel: 'Past Client Nurture — Annual Touches — Email',
                usageType: 'campaign_annual_touch',
                selectionContexts: ['campaign_annual_touch'],
                description: 'Reusable annual-touch message.',
                contextType: 'campaign',
                contextId: 42,
                presetMeta: [
                    'surface_rules' => ['direct_selection' => true],
                ],
                catalogMeta: [
                    'surface_rules' => ['direct_selection' => true],
                ],
            ),
            createdBy: $user,
        );

        $this->assertSame(CreateReusableMessageTemplateAction::SOURCE, $preset->source);
        $this->assertSame('Birthday Check-In', $preset->name);
        $this->assertSame('email', $preset->channel);
        $this->assertEquals(['campaign_touch_due'], $preset->dispatch_keys);
        $this->assertSame('campaign_annual_touch', data_get($preset->meta, 'authoring.context_key'));
        $this->assertEquals(
            ['campaign_annual_touch'],
            data_get($preset->meta, 'authoring.selection_contexts'),
        );
        $this->assertTrue((bool) data_get($preset->meta, 'surface_rules.direct_selection'));

        $template = MessageTemplate::query()->where('key', $preset->key)->firstOrFail();
        $this->assertSame(CreateReusableMessageTemplateAction::SOURCE, $template->source);
        $this->assertEquals([
            'subject' => 'Happy birthday',
            'body' => 'Hope you have a great birthday.'
        ], $template->currentPayload());

        $catalogEntry = MessageTemplateCatalogEntry::query()
            ->where('message_template_preset_id', $preset->getKey())
            ->sole();

        $this->assertSame('campaigns', $catalogEntry->module_key);
        $this->assertSame('campaigns', $catalogEntry->surface);
        $this->assertSame('campaign:past_client_nurture:annual_touches:email', $catalogEntry->group_key);
        $this->assertSame('Past Client Nurture — Annual Touches — Email', $catalogEntry->group_label);
        $this->assertSame('campaign_annual_touch', $catalogEntry->usage_type);
        $this->assertSame('campaign', $catalogEntry->context_type);
        $this->assertSame(42, $catalogEntry->context_id);
        $this->assertSame('campaign_annual_touch', data_get($catalogEntry->meta, 'authoring.context_key'));
        $this->assertTrue((bool) data_get($catalogEntry->meta, 'surface_rules.direct_selection'));

        app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => 'Updated subject',
                'body' => 'Updated body',
            ],
            createdBy: $user,
        );

        $definitions = app(ReusableMessageTemplateCatalog::class)->definitions(
            channels: ['email'],
            purpose: 'marketing',
            selectionContext: 'campaign_annual_touch',
        );

        $this->assertCount(1, $definitions);
        $this->assertSame((int) $preset->getKey(), $definitions[0]['id']);
        $this->assertSame('Birthday Check-In', $definitions[0]['name']);
        $this->assertSame('email', $definitions[0]['channel']);
        $this->assertEquals([
            'subject' => 'Updated subject',
            'body' => 'Updated body',
        ], $definitions[0]['payload']);

        $this->assertSame(1, MessageTemplatePreset::query()->count());
        $this->assertSame(1, MessageTemplate::query()->count());
        $this->assertSame(2, $template->versions()->count());
    }
}