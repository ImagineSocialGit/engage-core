<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
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

    public function test_it_creates_a_catalogued_versioned_reusable_message_and_reads_the_latest_version(): void
    {
        $user = User::factory()->create();

        $preset = app(CreateReusableMessageTemplateAction::class)->handle(
            name: 'Monthly Realtor Update',
            channel: 'email',
            purpose: 'marketing',
            scope: 'broadcast',
            dispatchKey: Broadcast::DEFAULT_DISPATCH_KEY,
            messageType: Broadcast::DEFAULT_MESSAGE_TYPE,
            payloadClass: EmailPayload::class,
            queue: 'marketing',
            payload: [
                'subject' => 'Original subject',
                'body' => 'Original body',
            ],
            createdBy: $user,
        );

        $this->assertSame(CreateReusableMessageTemplateAction::SOURCE, $preset->source);
        $this->assertSame('Monthly Realtor Update', $preset->name);
        $this->assertSame('email', $preset->channel);
        $this->assertEquals([Broadcast::DEFAULT_DISPATCH_KEY], $preset->dispatch_keys);

        $template = MessageTemplate::query()->where('key', $preset->key)->firstOrFail();
        $this->assertSame(CreateReusableMessageTemplateAction::SOURCE, $template->source);
        $this->assertEquals([
            'subject' => 'Original subject',
            'body' => 'Original body',
        ], $template->currentPayload());

        $catalogEntry = MessageTemplateCatalogEntry::query()
            ->where('message_template_preset_id', $preset->getKey())
            ->sole();

        $this->assertSame('broadcasts', $catalogEntry->module_key);
        $this->assertSame(CreateReusableMessageTemplateAction::SURFACE, $catalogEntry->surface);
        $this->assertSame(CreateReusableMessageTemplateAction::groupKey('email'), $catalogEntry->group_key);
        $this->assertSame(CreateReusableMessageTemplateAction::groupLabel('email'), $catalogEntry->group_label);
        $this->assertSame(CreateReusableMessageTemplateAction::USAGE_TYPE, $catalogEntry->usage_type);

        app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => 'Updated subject',
                'body' => 'Updated body',
            ],
            createdBy: $user,
        );

        $definitions = app(ReusableMessageTemplateCatalog::class)->definitions(['email']);

        $this->assertCount(1, $definitions);
        $this->assertSame((int) $preset->getKey(), $definitions[0]['id']);
        $this->assertSame('Monthly Realtor Update', $definitions[0]['name']);
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