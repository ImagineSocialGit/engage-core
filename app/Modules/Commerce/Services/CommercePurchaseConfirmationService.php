<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Data\CommercePurchaseConfirmation;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CommercePurchaseConfirmationService
{
    public const AUTOMATION_EVENT_KEY = 'commerce.purchase_confirmed';

    private const SOURCE = 'commerce';
    private const OUTCOME_VERSION = 1;

    public function __construct(
        private readonly AutomationEventOutbox $outbox,
        private readonly CommercePurchaseConfirmationReader $reader,
    ) {}

    public function confirm(
        int $commerceOrderId,
        ?DateTimeInterface $occurredAt = null,
    ): ?CommercePurchaseConfirmation {
        if ($commerceOrderId < 1) {
            throw new RuntimeException(
                'Commerce purchase confirmation requires a valid order identity.',
            );
        }

        return DB::transaction(function () use (
            $commerceOrderId,
            $occurredAt,
        ): ?CommercePurchaseConfirmation {
            $order = CommerceOrder::query()
                ->lockForUpdate()
                ->find($commerceOrderId);

            if (! $order instanceof CommerceOrder) {
                throw new RuntimeException(
                    "Commerce order [{$commerceOrderId}] does not exist.",
                );
            }

            $providerKey = trim((string) $order->provider);
            $externalOrderId = trim((string) $order->external_id);

            if ($providerKey === '' || $externalOrderId === '') {
                throw new RuntimeException(
                    'Commerce purchase confirmation requires authoritative provider order identity.',
                );
            }

            $externalOutcomeId = $this->externalOutcomeId(
                providerKey: $providerKey,
                externalOrderId: $externalOrderId,
            );

            $existing = CommerceOrderEvent::withTrashed()
                ->where('provider', $providerKey)
                ->where('external_id', $externalOutcomeId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof CommerceOrderEvent) {
                if ((int) $existing->commerce_order_id !== $commerceOrderId
                    || $existing->event !== CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED
                    || $existing->source !== self::SOURCE
                ) {
                    throw new RuntimeException(
                        'Commerce purchase confirmation identity conflicts with existing order-event evidence.',
                    );
                }

                if ($existing->trashed()) {
                    $existing->restore();
                }

                $confirmation = $this->reader->fromEvent($existing);
                $this->recordAutomationEvent($order, $confirmation);

                return $confirmation;
            }

            if (! $this->eligible($order)) {
                return null;
            }

            $itemIds = CommerceOrderItem::query()
                ->where('commerce_order_id', $order->getKey())
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($itemIds === []) {
                return null;
            }

            $confirmation = new CommercePurchaseConfirmation(
                commerceOrderId: $commerceOrderId,
                commerceOrderItemIds: $itemIds,
                contactId: $order->contact_id !== null
                    ? (int) $order->contact_id
                    : null,
                commerceCustomerId: $order->commerce_customer_id !== null
                    ? (int) $order->commerce_customer_id
                    : null,
                providerKey: $providerKey,
                source: trim((string) $order->source) !== ''
                    ? trim((string) $order->source)
                    : 'provider',
                externalOrderId: $externalOrderId,
                occurredAt: $this->occurredAt($order, $occurredAt),
            );

            CommerceOrderEvent::query()->create([
                'commerce_order_id' => $order->getKey(),
                'event' => CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED,
                'from_status' => null,
                'to_status' => $order->status,
                'occurred_at' => $confirmation->occurredAt,
                'source' => self::SOURCE,
                'provider' => $providerKey,
                'external_id' => $externalOutcomeId,
                'payload' => $confirmation->toArray(),
                'meta' => [
                    'outcome_version' => self::OUTCOME_VERSION,
                ],
            ]);

            $this->recordAutomationEvent($order, $confirmation);

            return $confirmation;
        }, 3);
    }

    private function eligible(CommerceOrder $order): bool
    {
        if ($order->status === CommerceOrder::STATUS_CANCELLED) {
            return false;
        }

        return in_array($order->financial_status, [
            CommerceOrder::FINANCIAL_STATUS_PAID,
            CommerceOrder::FINANCIAL_STATUS_PARTIALLY_REFUNDED,
        ], true);
    }

    private function recordAutomationEvent(
        CommerceOrder $order,
        CommercePurchaseConfirmation $confirmation,
    ): void {
        $this->outbox->record(
            AutomationEventData::forSubject(
                eventKey: self::AUTOMATION_EVENT_KEY,
                subject: $order,
                contactId: $confirmation->contactId,
                occurredAt: $confirmation->occurredAt,
                payload: $confirmation->toArray(),
                meta: [
                    'source_module' => 'commerce',
                    'outcome_version' => self::OUTCOME_VERSION,
                ],
            ),
            idempotencyKey: 'commerce-purchase-confirmed:'.$confirmation->commerceOrderId,
        );
    }

    private function occurredAt(
        CommerceOrder $order,
        ?DateTimeInterface $occurredAt,
    ): CarbonImmutable {
        if ($occurredAt !== null) {
            return CarbonImmutable::instance($occurredAt);
        }

        if ($order->ordered_at !== null) {
            return $order->ordered_at->toImmutable();
        }

        return CarbonImmutable::now('UTC');
    }

    private function externalOutcomeId(
        string $providerKey,
        string $externalOrderId,
    ): string {
        return 'purchase-confirmed:'.hash(
            'sha256',
            trim($providerKey).'|'.trim($externalOrderId),
        );
    }
}