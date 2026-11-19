<?php

namespace Tests\Feature\Webhooks;

use App\Support\Webhooks\Models\WebhookInboxReceipt;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class WebhookInboxVerifiedReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_intake_is_durable_pending_and_idempotent(): void
    {
        $inbox = app(WebhookInbox::class);
        $first = $inbox->receiveVerified(
            provider: 'square',
            payload: ['event_id' => 'event-1', 'data' => ['payment' => 'p1']],
            providerEventId: 'event-1',
            eventType: 'payment.updated',
            storedPayload: ['event_id' => 'event-1', 'payment_id' => 'p1'],
        );
        $second = $inbox->receiveVerified(
            provider: 'square',
            payload: ['event_id' => 'event-1', 'data' => ['payment' => 'p1']],
            providerEventId: 'event-1',
            eventType: 'payment.updated',
            storedPayload: ['event_id' => 'event-1', 'payment_id' => 'p1'],
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(WebhookInboxReceipt::STATUS_RECEIVED, $first->fresh()->status);
        $this->assertSame(0, $first->fresh()->attempts);
        $this->assertSame('event-1', $first->fresh()->payload['event_id']);
        $this->assertSame('p1', $first->fresh()->payload['payment_id']);
        $this->assertSame(1, WebhookInboxReceipt::query()->count());
    }

    public function test_existing_provider_id_cannot_be_reused_with_changed_payload(): void
    {
        $inbox = app(WebhookInbox::class);
        $inbox->receiveVerified('square', ['payment' => 'p1'], providerEventId: 'event-1');

        $this->expectException(LogicException::class);
        $inbox->receiveVerified('square', ['payment' => 'p2'], providerEventId: 'event-1');
    }

    public function test_existing_synchronous_inbox_processing_remains_compatible(): void
    {
        $inbox = app(WebhookInbox::class);
        $calls = 0;

        $receipt = $inbox->process(
            provider: 'legacy',
            payload: ['id' => 'legacy-1'],
            processor: static function () use (&$calls): array {
                $calls++;
                return ['ok' => true];
            },
            providerEventId: 'legacy-1',
        );

        $duplicate = $inbox->process(
            provider: 'legacy',
            payload: ['id' => 'legacy-1'],
            processor: static function () use (&$calls): array {
                $calls++;
                return ['ok' => false];
            },
            providerEventId: 'legacy-1',
        );

        $this->assertSame($receipt->getKey(), $duplicate->getKey());
        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $duplicate->status);
        $this->assertSame(1, $calls);
    }

    public function test_pending_verified_receipt_recovers_and_completes_once(): void
    {
        $inbox = app(WebhookInbox::class);
        $receipt = $inbox->receiveVerified('square', ['payment' => 'p1'], providerEventId: 'event-1');
        $called = 0;

        $finished = $inbox->recoverStoredReceipt(
            (int) $receipt->getKey(),
            static fn (WebhookInboxReceipt $stored): bool => $stored->provider === 'square',
            static function (WebhookInboxReceipt $stored) use (&$called): array {
                $called++;
                return ['status' => 'inventory_adjusted'];
            },
        );
        $again = $inbox->recoverStoredReceipt(
            (int) $receipt->getKey(),
            static fn (): bool => true,
            static function () use (&$called): array {
                $called++;
                return [];
            },
        );

        $this->assertSame(1, $called);
        $this->assertNull($again);
        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $finished->status);
        $this->assertSame(['status' => 'inventory_adjusted'], $finished->outcome);
    }

    public function test_failure_preserves_receipt_for_recovery(): void
    {
        $inbox = app(WebhookInbox::class);
        $receipt = $inbox->receiveVerified('square', ['payment' => 'p1'], providerEventId: 'event-1');

        try {
            $inbox->recoverStoredReceipt(
                (int) $receipt->getKey(),
                static fn (): bool => true,
                static function (): never {
                    throw new RuntimeException('Transient authority failure.');
                },
            );
            $this->fail('Expected failed processing to remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Transient authority failure.', $exception->getMessage());
        }

        $this->assertSame(WebhookInboxReceipt::STATUS_RETRYABLE_FAILED, $receipt->fresh()->status);

        $recovered = $inbox->recoverStoredReceipt(
            (int) $receipt->getKey(),
            static fn (): bool => true,
            static fn (): array => ['status' => 'inventory_adjusted'],
        );

        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $recovered->status);
        $this->assertSame(2, $recovered->attempts);
    }
}