<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Actions\Sms\ProcessInboundSmsMessageAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Models\InboundMessageReceipt;
use App\Modules\InboundMessaging\Services\InboundMessageRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProcessInboundSmsMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_processing_is_retryable_and_completion_is_stored_once(): void
    {
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

        $receipt = $inboundMessage->receipt()->firstOrFail();

        $this->assertSame(InboundMessageReceipt::STATUS_RETRYABLE_FAILED, $receipt->status);
        $this->assertSame(1, $receipt->attempts);
        $this->assertSame('Simulated inbound handler failure.', $receipt->last_error);

        $successfulRouter = Mockery::mock(InboundMessageRouter::class);
        $successfulRouter->shouldReceive('route')
            ->once()
            ->andReturn('Stored provider response');

        $action = new ProcessInboundSmsMessageAction($successfulRouter);

        $this->assertSame('Stored provider response', $action->handle($inboundMessage));
        $this->assertSame('Stored provider response', $action->handle($inboundMessage));

        $receipt->refresh();

        $this->assertSame(InboundMessageReceipt::STATUS_COMPLETED, $receipt->status);
        $this->assertSame(2, $receipt->attempts);
        $this->assertSame('Stored provider response', $receipt->response_message);
        $this->assertNull($receipt->last_error);
        $this->assertNotNull($receipt->completed_at);
    }
}