<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Actions\Sms\ProcessInboundSmsMessageAction;
use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\InboundMessageRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProcessInboundSmsMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_processor_does_not_own_business_processed_state(): void
    {
        Event::fake([InboundMessageReceived::class]);

        $inboundMessage = app(RecordInboundMessageAction::class)->handle([
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'evt_processing_retry',
            'provider_message_id' => 'msg_processing_retry',
            'classification' => InboundMessage::CLASSIFICATION_IGNORED,
            'received_at' => now(),
        ]);

        $failingRouter = Mockery::mock(InboundMessageRouter::class);
        $failingRouter->shouldReceive('route')
            ->once()
            ->andThrow(new RuntimeException('Simulated inbound handler failure.'));

        try {
            (new ProcessInboundSmsMessageAction($failingRouter))->handle($inboundMessage);
            $this->fail('Inbound processing should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated inbound handler failure.', $exception->getMessage());
        }

        $inboundMessage->refresh();
        $this->assertNull($inboundMessage->processed_at);

        $successfulRouter = Mockery::mock(InboundMessageRouter::class);
        $successfulRouter->shouldReceive('route')
            ->once()
            ->andReturn('Stored provider response');

        $action = new ProcessInboundSmsMessageAction($successfulRouter);

        $this->assertSame('Stored provider response', $action->handle($inboundMessage));

        $inboundMessage->refresh();
        $this->assertNull($inboundMessage->processed_at);
        Event::assertDispatchedTimes(InboundMessageReceived::class, 2);
    }
}