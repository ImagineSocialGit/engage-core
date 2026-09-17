<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\EditScheduledMessageBulkContentAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageBulkEdit;
use App\Modules\Messaging\Services\ScheduledMessageContentEditor;
use App\Modules\Webinars\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledMessageBulkContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_targets_existing_source_template_only_and_keeps_individual_priority(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();
        $webinar = Webinar::factory()->create();
        $other = Webinar::factory()->create();
        $template = MessageTemplate::query()->create([
            'key' => 'sms.transactional.bulk_fixture',
            'name' => 'Bulk Fixture',
            'channel' => 'sms',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'source_version' => '1',
            'is_customized' => false,
        ]);
        $version = MessageTemplateVersion::query()->create([
            'message_template_id' => $template->getKey(),
            'version' => 1,
            'content' => ['message' => 'Original text'],
            'renderer_key' => 'sms',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', 'bulk-fixture'),
        ]);
        $chain = MessageChain::query()->create([
            'key' => 'bulk-fixture',
            'name' => 'Bulk Fixture',
            'status' => MessageChain::STATUS_ACTIVE,
        ]);
        $chainVersion = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'content_hash' => hash('sha256', 'bulk-chain-fixture'),
            'published_at' => now(),
        ]);
        $make = function (Webinar $origin) use ($contact, $version, $chainVersion): ScheduledMessage {
            $enrollment = MessageChainEnrollment::query()->create([
                'message_chain_version_id' => $chainVersion->getKey(),
                'recipient_type' => $contact->getMorphClass(),
                'recipient_id' => $contact->getKey(),
                'origin_type' => $origin->getMorphClass(),
                'origin_id' => $origin->getKey(),
                'status' => MessageChainEnrollment::STATUS_ACTIVE,
                'started_at' => now(),
            ]);
            return ScheduledMessage::factory()->sms()->forRecipient($contact)->create([
                'message_template_version_id' => $version->getKey(),
                'message_chain_enrollment_id' => $enrollment->getKey(),
                'send_at' => now()->addHour(),
            ]);
        };
        $included = $make($webinar);
        $excluded = $make($other);

        app(EditScheduledMessageBulkContentAction::class)->save(
            $actor, 'webinar', (int) $webinar->getKey(), (int) $version->getKey(),
            ['message' => 'Bulk text'],
        );
        $this->assertSame(1, ScheduledMessageBulkEdit::query()->count());
        $editor = app(ScheduledMessageContentEditor::class);
        $this->assertSame(['message' => 'Bulk text'], $editor->override($included->fresh()));
        $this->assertSame([], $editor->override($excluded->fresh()));
        $future = $make($webinar);
        $this->assertSame([], $editor->override($future->fresh()));

        app(\App\Modules\Messaging\Actions\EditScheduledMessageContentAction::class)
            ->save($included, $actor, ['message' => 'My individual text']);
        $this->assertSame(
            ['message' => 'My individual text'],
            $editor->override($included->fresh()),
        );

        app(\App\Modules\Messaging\Actions\EditScheduledMessageContentAction::class)
            ->restore($included, $actor);
        $this->assertSame([], $editor->override($included->fresh()));
    }
}