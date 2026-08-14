<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ConditionChecker;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use App\Modules\Messaging\Services\MessageRecipientGateRegistry;
use App\Modules\Messaging\Services\MessageRecipientPayloadResolver;
use App\Modules\Messaging\Services\ScheduledMessageGate;
use Tests\TestCase;

class ScheduledMessageContactRecipientGateTest extends TestCase
{
    public function test_contact_messages_run_module_recipient_gates_after_standard_eligibility(): void
    {
        $contact = new Contact(['email' => 'person@example.test']);
        $message = new ScheduledMessage([
            'channel' => 'email',
            'message_type' => 'post_missed',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload' => ['to' => 'person@example.test'],
            'meta' => [],
        ]);
        $message->setRelation('recipient', $contact);
        $message->setRelation('context', null);

        $conditions = $this->mock(ConditionChecker::class, function ($mock): void {
            $mock->shouldReceive('passes')->once()->andReturnTrue();
        });
        $eligibility = $this->mock(MessageEligibilityGate::class, function ($mock): void {
            $mock->shouldReceive('allows')->once()->andReturnTrue();
        });
        $payloadResolver = $this->mock(MessageRecipientPayloadResolver::class, function ($mock): void {
            $mock->shouldReceive('conditionContext')->once()->andReturn([]);
        });
        $registry = $this->mock(MessageRecipientGateRegistry::class, function ($mock): void {
            $mock->shouldReceive('denialReason')
                ->once()
                ->andReturn('module_preflight_denied');
        });

        $gate = new ScheduledMessageGate(
            conditionChecker: $conditions,
            messageEligibilityGate: $eligibility,
            payloadResolver: $payloadResolver,
            recipientGateRegistry: $registry,
        );

        $this->assertSame('module_preflight_denied', $gate->denialReason($message));
    }
}