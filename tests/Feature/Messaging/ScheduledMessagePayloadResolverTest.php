<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageRenderContext;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledMessagePayloadResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lazily_freezes_only_referenced_values_and_reuses_them_for_retries(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Planned Name',
            'email' => 'fixture@example.test',
        ]);

        $template = MessageTemplate::query()->create([
            'key' => 'email.transactional.fixture.rendering',
            'name' => 'Rendering Fixture',
            'description' => null,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'source_version' => '1',
            'is_customized' => false,
            'customized_at' => null,
        ]);

        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => 'Hello {first_name}',
                'body' => 'Reference {runtime_code}.',
            ],
        );

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => null,
            'context_id' => null,
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'message_template_version_id' => $version->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'message_type' => 'rendering',
            'payload_class' => EmailPayload::class,
            'payload' => [
                'to' => 'fixture@example.test',
                'contact_id' => $contact->getKey(),
                'tokens' => [
                    'runtime_code' => 'ABC-123',
                    'unused_value' => 'do-not-freeze',
                ],
            ],
            'meta' => [],
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $contact->forceFill([
            'first_name' => 'Rendered Name',
        ])->save();

        $resolver = app(ScheduledMessagePayloadResolver::class);
        $firstPayload = $resolver->resolve($scheduledMessage);

        $this->assertSame('Hello Rendered Name', $firstPayload->subject());
        $this->assertSame('Reference ABC-123.', $firstPayload->text());

        $renderContext = ScheduledMessageRenderContext::query()->sole();

        $this->assertEquals([
            'first_name' => 'Rendered Name',
            'runtime_code' => 'ABC-123',
        ], $renderContext->values);
        $this->assertNotSame('', $renderContext->content_hash);
        $this->assertNotNull($renderContext->rendered_at);

        $scheduledMessage->refresh();

        $this->assertArrayNotHasKey('subject', $scheduledMessage->payload);
        $this->assertArrayNotHasKey('body', $scheduledMessage->payload);
        $this->assertArrayNotHasKey('tokens', $scheduledMessage->payload);

        $contact->forceFill([
            'first_name' => 'Changed After Render',
        ])->save();

        $retryPayload = $resolver->resolve($scheduledMessage->fresh());

        $this->assertSame('Hello Rendered Name', $retryPayload->subject());
        $this->assertSame('Reference ABC-123.', $retryPayload->text());
        $this->assertSame(1, ScheduledMessageRenderContext::query()->count());
        $this->assertEquals(
            $renderContext->values,
            ScheduledMessageRenderContext::query()->sole()->values,
        );
    }
}