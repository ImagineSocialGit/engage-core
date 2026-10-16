<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\HandleEmailProviderEventAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSuppressionRuntimeSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounce_suppression_immediately_skips_pending_email_but_not_sms(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'bounce@example.test',
            'phone' => '+15551234567',
        ]);

        $pendingEmail = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'channel' => MessageChannel::Email->value,
                'status' => ScheduledMessage::STATUS_PENDING,
                'send_at' => now()->addDay(),
            ]);

        $pendingSms = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'channel' => MessageChannel::Sms->value,
                'status' => ScheduledMessage::STATUS_PENDING,
                'send_at' => now()->addDay(),
            ]);

        app(HandleEmailProviderEventAction::class)->handle([
            'type' => 'email.bounced',
            'data' => [
                'to' => ['bounce@example.test'],
            ],
        ], 'evt-bounce-runtime');

        $this->assertDatabaseHas('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'bounce@example.test',
            'reason' => MessageSuppression::REASON_BOUNCE,
            'released_at' => null,
        ]);

        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $pendingEmail->refresh()->status,
        );

        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $pendingSms->refresh()->status,
        );
    }

}