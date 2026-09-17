<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Messaging\Actions\ClaimScheduledMessageForSendingAction;
use App\Modules\Messaging\Actions\ControlScheduledMessageAction;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageOperationalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduledMessageOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hold_reschedule_and_resume_preserve_audited_manual_override_and_claim_due_time(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        $actor = User::factory()->create();
        $message = ScheduledMessage::factory()->create([
            'send_at' => now()->addHour(),
        ]);
        $action = app(ControlScheduledMessageAction::class);

        $action->hold($message, $actor, 'Client requested pause');
        Carbon::setTestNow('2026-09-17 14:00:00 UTC');
        $this->assertNull(app(ClaimScheduledMessageForSendingAction::class)->handle($message));
        $this->assertSame(ScheduledMessage::OPERATIONAL_HELD, $message->fresh()->operational_state);

        $replacement = now()->addHour();
        $action->reschedule($message, $actor, $replacement, 'New approved time');
        $this->assertTrue($message->fresh()->send_at->equalTo($replacement));
        $this->assertNotNull($message->fresh()->manual_schedule_override_at);
        $this->assertNull(app(ClaimScheduledMessageForSendingAction::class)->handle($message));

        $action->resume($message, $actor, 'Approved to send');
        $this->assertNull(app(ClaimScheduledMessageForSendingAction::class)->handle($message));
        Carbon::setTestNow('2026-09-17 15:00:00 UTC');
        $this->assertNotNull(app(ClaimScheduledMessageForSendingAction::class)->handle($message));

        $events = ScheduledMessageOperationalEvent::query()
            ->where('scheduled_message_id', $message->getKey())
            ->orderBy('id')->get();
        $this->assertSame(['hold', 'reschedule', 'resume'], $events->pluck('action')->all());
        $this->assertSame([$actor->getKey(), $actor->getKey(), $actor->getKey()], $events->pluck('actor_id')->all());
        $this->assertTrue($events[1]->previous_send_at->equalTo(Carbon::parse('2026-09-17 13:00:00 UTC')));
        $this->assertTrue($events[1]->current_send_at->equalTo($replacement));
    }

    public function test_cancel_is_distinct_terminal_outcome_and_cannot_be_resumed_or_claimed(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        $actor = User::factory()->create();
        $message = ScheduledMessage::factory()->create([
            'send_at' => now()->addHour(),
        ]);
        $action = app(ControlScheduledMessageAction::class);
        $action->hold($message, $actor);
        $action->cancel($message, $actor, 'Contact opted out');
        $cancelled = $message->fresh();

        $this->assertSame(ScheduledMessage::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(ScheduledMessage::OPERATIONAL_CANCELLED, $cancelled->operational_state);
        $this->assertSame(ScheduledMessage::STATUS_CANCELLED, $cancelled->terminalOutboxEvent->event_type);
        $this->assertSame('cancelled_by_operator', $cancelled->terminalOutboxEvent->reason_code);
        $this->assertTrue(ScheduledMessageTerminalResult::fromScheduledMessage($cancelled)->isCancelled());
        $this->assertNull(app(ClaimScheduledMessageForSendingAction::class)->handle($message));
        $this->assertSame(2, $cancelled->operationalEvents()->count());

        $this->expectException(ValidationException::class);
        $action->resume($message, $actor);
    }

    public function test_sending_and_sent_messages_reject_operator_changes(): void
    {
        Queue::fake();
        $actor = User::factory()->create();
        $message = ScheduledMessage::factory()->sending()->create();

        $this->expectException(ValidationException::class);
        app(ControlScheduledMessageAction::class)->cancel($message, $actor);
    }
}