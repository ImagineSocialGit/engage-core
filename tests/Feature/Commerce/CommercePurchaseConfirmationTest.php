<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Services\CommercePurchaseConfirmationReader;
use App\Modules\Commerce\Services\CommercePurchaseConfirmationService;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercePurchaseConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_order_records_one_durable_purchase_confirmation_and_automation_outcome(): void
    {
        $order = CommerceOrder::factory()->create([
            'status' => CommerceOrder::STATUS_OPEN,
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_PAID,
            'provider' => 'provider-a',
            'external_id' => 'order-confirmed-1',
            'ordered_at' => CarbonImmutable::parse('2026-09-24T19:00:00Z'),
        ]);
        $firstItem = CommerceOrderItem::factory()->create([
            'commerce_order_id' => $order->getKey(),
            'provider' => 'provider-a',
            'external_id' => 'line-confirmed-1',
        ]);
        $secondItem = CommerceOrderItem::factory()->create([
            'commerce_order_id' => $order->getKey(),
            'provider' => 'provider-a',
            'external_id' => 'line-confirmed-2',
        ]);
        $occurredAt = CarbonImmutable::parse('2026-09-24T20:15:00Z');

        $service = app(CommercePurchaseConfirmationService::class);
        $confirmation = $service->confirm(
            commerceOrderId: (int) $order->getKey(),
            occurredAt: $occurredAt,
        );

        $this->assertNotNull($confirmation);
        $this->assertSame((int) $order->getKey(), $confirmation->commerceOrderId);
        $this->assertSame(
            [(int) $firstItem->getKey(), (int) $secondItem->getKey()],
            $confirmation->commerceOrderItemIds,
        );
        $this->assertSame((int) $order->contact_id, $confirmation->contactId);
        $this->assertSame(
            (int) $order->commerce_customer_id,
            $confirmation->commerceCustomerId,
        );
        $this->assertSame('provider-a', $confirmation->providerKey);
        $this->assertSame('provider', $confirmation->source);
        $this->assertSame('order-confirmed-1', $confirmation->externalOrderId);
        $this->assertTrue($occurredAt->equalTo($confirmation->occurredAt));

        $event = CommerceOrderEvent::query()
            ->where('commerce_order_id', $order->getKey())
            ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
            ->firstOrFail();

        $this->assertSame('commerce', $event->source);
        $this->assertSame('provider-a', $event->provider);
        $this->assertEqualsCanonicalizing($confirmation->toArray(), $event->payload);
        $this->assertSame(1, $event->meta['outcome_version'] ?? null);

        $outbox = AutomationEventOutboxEvent::query()
            ->where('idempotency_key', 'commerce-purchase-confirmed:'.$order->getKey())
            ->firstOrFail();

        $this->assertSame(
            CommercePurchaseConfirmationService::AUTOMATION_EVENT_KEY,
            $outbox->event_key,
        );
        $this->assertSame((int) $order->contact_id, $outbox->contact_id);
        $this->assertSame((string) $order->getKey(), (string) $outbox->subject_id);
        $this->assertEqualsCanonicalizing($confirmation->toArray(), $outbox->payload);

        $read = app(CommercePurchaseConfirmationReader::class)->forOrder(
            (int) $order->getKey(),
        );

        $this->assertNotNull($read);
        $this->assertEqualsCanonicalizing($confirmation->toArray(), $read->toArray());
    }

    public function test_repeated_confirmation_reuses_original_outcome_and_outbox_identity(): void
    {
        $order = CommerceOrder::factory()->create([
            'status' => CommerceOrder::STATUS_OPEN,
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_PAID,
            'provider' => 'provider-a',
            'external_id' => 'order-confirmed-2',
        ]);
        CommerceOrderItem::factory()->create([
            'commerce_order_id' => $order->getKey(),
            'provider' => 'provider-a',
            'external_id' => 'line-confirmed-3',
        ]);

        $service = app(CommercePurchaseConfirmationService::class);
        $first = $service->confirm(
            (int) $order->getKey(),
            CarbonImmutable::parse('2026-09-24T20:00:00Z'),
        );

        $order->forceFill([
            'contact_id' => null,
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_PARTIALLY_REFUNDED,
        ])->save();

        $second = $service->confirm(
            (int) $order->getKey(),
            CarbonImmutable::parse('2026-09-25T20:00:00Z'),
        );

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertEqualsCanonicalizing($first->toArray(), $second->toArray());
        $this->assertSame(
            1,
            CommerceOrderEvent::query()
                ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                ->count(),
        );
        $this->assertSame(
            1,
            AutomationEventOutboxEvent::query()
                ->where('idempotency_key', 'commerce-purchase-confirmed:'.$order->getKey())
                ->count(),
        );
    }

    public function test_pending_order_is_not_confirmed_until_authoritative_state_becomes_paid(): void
    {
        $order = CommerceOrder::factory()->create([
            'status' => CommerceOrder::STATUS_OPEN,
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_PENDING,
            'provider' => 'provider-a',
            'external_id' => 'order-confirmed-3',
        ]);
        CommerceOrderItem::factory()->create([
            'commerce_order_id' => $order->getKey(),
            'provider' => 'provider-a',
            'external_id' => 'line-confirmed-4',
        ]);

        $service = app(CommercePurchaseConfirmationService::class);

        $this->assertNull($service->confirm((int) $order->getKey()));
        $this->assertSame(
            0,
            CommerceOrderEvent::query()
                ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                ->count(),
        );
        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());

        $order->forceFill([
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_PAID,
        ])->save();

        $this->assertNotNull($service->confirm((int) $order->getKey()));
        $this->assertSame(
            1,
            CommerceOrderEvent::query()
                ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                ->count(),
        );
        $this->assertSame(1, AutomationEventOutboxEvent::query()->count());
    }

    public function test_cancelled_or_fully_refunded_first_observation_does_not_create_purchase_confirmation(): void
    {
        $cancelled = CommerceOrder::factory()->create([
            'status' => CommerceOrder::STATUS_CANCELLED,
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_PAID,
            'provider' => 'provider-a',
            'external_id' => 'order-cancelled',
        ]);
        CommerceOrderItem::factory()->create([
            'commerce_order_id' => $cancelled->getKey(),
            'provider' => 'provider-a',
            'external_id' => 'line-cancelled',
        ]);

        $refunded = CommerceOrder::factory()->create([
            'status' => CommerceOrder::STATUS_CLOSED,
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_REFUNDED,
            'provider' => 'provider-a',
            'external_id' => 'order-refunded',
        ]);
        CommerceOrderItem::factory()->create([
            'commerce_order_id' => $refunded->getKey(),
            'provider' => 'provider-a',
            'external_id' => 'line-refunded',
        ]);

        $service = app(CommercePurchaseConfirmationService::class);

        $this->assertNull($service->confirm((int) $cancelled->getKey()));
        $this->assertNull($service->confirm((int) $refunded->getKey()));
        $this->assertSame(
            0,
            CommerceOrderEvent::query()
                ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                ->count(),
        );
        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());
    }
}