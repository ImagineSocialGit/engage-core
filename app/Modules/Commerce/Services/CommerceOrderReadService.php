<?php

namespace App\Modules\Commerce\Services;

use App\Models\User;
use App\Modules\Commerce\Data\CommercePurchaseConfirmation;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

final class CommerceOrderReadService
{
    public function __construct(
        private readonly ContactVisibility $contactVisibility,
        private readonly UserAccessService $access,
        private readonly CommercePurchaseConfirmationReader $confirmations,
    ) {}

    public function orders(
        User $user,
        int $perPage = 30,
    ): LengthAwarePaginator {
        return $this->visibleOrdersQuery($user)
            ->with(['contact', 'commerceCustomer'])
            ->withCount([
                'items',
                'events as purchase_confirmed_count' => static fn (Builder $query) => $query
                    ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                    ->where('source', 'commerce'),
            ])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)))
            ->withQueryString();
    }

    /**
     * @return array<string, int>
     */
    public function summary(User $user): array
    {
        $base = $this->visibleOrdersQuery($user);

        return [
            'total' => (clone $base)->count(),
            'paid' => (clone $base)
                ->where('financial_status', CommerceOrder::FINANCIAL_STATUS_PAID)
                ->count(),
            'refunded' => (clone $base)
                ->whereIn('financial_status', [
                    CommerceOrder::FINANCIAL_STATUS_PARTIALLY_REFUNDED,
                    CommerceOrder::FINANCIAL_STATUS_REFUNDED,
                ])
                ->count(),
            'purchase_confirmed' => (clone $base)
                ->whereHas('events', static fn (Builder $query) => $query
                    ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                    ->where('source', 'commerce'))
                ->count(),
        ];
    }

    public function canView(User $user, CommerceOrder $order): bool
    {
        if ($order->contact_id !== null) {
            $contact = $order->relationLoaded('contact')
                ? $order->contact
                : Contact::query()->find($order->contact_id);

            return $contact instanceof Contact
                && $this->contactVisibility->canView($user, $contact);
        }

        return $this->access->isActive($user)
            && $this->access->allows($user, 'contacts.view_all');
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(
        User $user,
        CommerceOrder $order,
    ): array {
        if (! $this->canView($user, $order)) {
            throw new RuntimeException(
                'Commerce order is not visible to the current CRM user.',
            );
        }

        $order->load([
            'contact',
            'commerceCustomer',
            'items' => static fn ($query) => $query
                ->with(['commerceProduct', 'commerceProductVariant'])
                ->orderBy('id'),
            'events' => static fn ($query) => $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id'),
        ]);

        $inventoryEffects = $this->inventoryEffects($order);

        return [
            'order' => $order,
            'items' => $order->items,
            'events' => $order->events,
            'purchase_confirmation' => $this->purchaseConfirmation($order),
            'inventory_effects' => $inventoryEffects,
            'inventory_effects_by_item' => $inventoryEffects
                ->groupBy(static function (CommerceInventoryEffect $effect): int {
                    $itemId = data_get($effect->meta, 'commerce_order_item_id');

                    return is_numeric($itemId) ? (int) $itemId : 0;
                })
                ->forget(0),
        ];
    }

    private function visibleOrdersQuery(User $user): Builder
    {
        $visibleContactIds = $this->contactVisibility
            ->apply(Contact::query(), $user)
            ->select('id');
        $mayViewUnlinked = $this->access->isActive($user)
            && $this->access->allows($user, 'contacts.view_all');

        return CommerceOrder::query()
            ->where(function (Builder $query) use ($visibleContactIds, $mayViewUnlinked): void {
                $query->whereIn('contact_id', $visibleContactIds);

                if ($mayViewUnlinked) {
                    $query->orWhereNull('contact_id');
                }
            });
    }

    /**
     * @return array{state:string,event:?CommerceOrderEvent,confirmation:?CommercePurchaseConfirmation,error:?string}
     */
    private function purchaseConfirmation(CommerceOrder $order): array
    {
        $event = $order->events
            ->first(static fn (CommerceOrderEvent $event): bool =>
                $event->event === CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED
                && $event->source === 'commerce');

        if (! $event instanceof CommerceOrderEvent) {
            return [
                'state' => 'absent',
                'event' => null,
                'confirmation' => null,
                'error' => null,
            ];
        }

        try {
            return [
                'state' => 'confirmed',
                'event' => $event,
                'confirmation' => $this->confirmations->fromEvent($event),
                'error' => null,
            ];
        } catch (RuntimeException $exception) {
            return [
                'state' => 'invalid',
                'event' => $event,
                'confirmation' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return Collection<int, CommerceInventoryEffect>
     */
    private function inventoryEffects(CommerceOrder $order): Collection
    {
        $itemIds = $order->items
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();
        $variantIds = $order->items
            ->pluck('commerce_product_variant_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($itemIds->isEmpty() || $variantIds->isEmpty()) {
            return collect();
        }

        return CommerceInventoryEffect::query()
            ->with([
                'adjustments' => static fn ($query) => $query
                    ->with('providerMapping')
                    ->orderBy('id'),
            ])
            ->whereIn('commerce_product_variant_id', $variantIds->all())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->filter(static function (CommerceInventoryEffect $effect) use ($order, $itemIds): bool {
                $meta = is_array($effect->meta) ? $effect->meta : [];
                $commerceOrderId = $meta['commerce_order_id'] ?? null;
                $commerceOrderItemId = $meta['commerce_order_item_id'] ?? null;

                return is_numeric($commerceOrderId)
                    && (int) $commerceOrderId === (int) $order->getKey()
                    && is_numeric($commerceOrderItemId)
                    && $itemIds->contains((int) $commerceOrderItemId);
            })
            ->values();
    }
}