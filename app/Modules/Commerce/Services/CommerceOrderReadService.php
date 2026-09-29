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
use Illuminate\Support\Str;
use RuntimeException;

final class CommerceOrderReadService
{
    public function __construct(
        private readonly ContactVisibility $contactVisibility,
        private readonly UserAccessService $access,
        private readonly CommercePurchaseConfirmationReader $confirmations,
    ) {}

    /** @param array<string, mixed> $input */
    public function filters(array $input): array
    {
        $search = trim((string) ($input['q'] ?? ''));
        $financialStatus = trim((string) ($input['financial_status'] ?? ''));
        $fulfillmentStatus = trim((string) ($input['fulfillment_status'] ?? ''));
        $provider = trim((string) ($input['provider'] ?? ''));
        $confirmation = trim((string) ($input['confirmation'] ?? ''));

        return [
            'q' => $search !== '' ? Str::limit($search, 120, '') : null,
            'financial_status' => in_array($financialStatus, [
                CommerceOrder::FINANCIAL_STATUS_PENDING,
                CommerceOrder::FINANCIAL_STATUS_AUTHORIZED,
                CommerceOrder::FINANCIAL_STATUS_PAID,
                CommerceOrder::FINANCIAL_STATUS_PARTIALLY_REFUNDED,
                CommerceOrder::FINANCIAL_STATUS_REFUNDED,
                CommerceOrder::FINANCIAL_STATUS_VOIDED,
            ], true) ? $financialStatus : null,
            'fulfillment_status' => in_array($fulfillmentStatus, [
                CommerceOrder::FULFILLMENT_STATUS_UNFULFILLED,
                CommerceOrder::FULFILLMENT_STATUS_PARTIAL,
                CommerceOrder::FULFILLMENT_STATUS_FULFILLED,
                CommerceOrder::FULFILLMENT_STATUS_RESTOCKED,
            ], true) ? $fulfillmentStatus : null,
            'provider' => $provider !== '' ? Str::limit($provider, 120, '') : null,
            'confirmation' => in_array($confirmation, ['confirmed', 'unconfirmed'], true)
                ? $confirmation
                : null,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function orders(
        User $user,
        array $filters = [],
        int $perPage = 30,
    ): LengthAwarePaginator {
        return $this->filteredOrdersQuery($user, $filters)
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

    /** @param array<string, mixed> $filters @return array<string, int> */
    public function summary(User $user, array $filters = []): array
    {
        $base = $this->filteredOrdersQuery($user, $filters);

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

    /** @return array<string, mixed> */
    public function filterOptions(User $user): array
    {
        return [
            'providers' => $this->visibleOrdersQuery($user)
                ->whereNotNull('provider')
                ->where('provider', '!=', '')
                ->distinct()
                ->orderBy('provider')
                ->pluck('provider')
                ->values()
                ->all(),
            'financial_statuses' => [
                CommerceOrder::FINANCIAL_STATUS_PENDING,
                CommerceOrder::FINANCIAL_STATUS_AUTHORIZED,
                CommerceOrder::FINANCIAL_STATUS_PAID,
                CommerceOrder::FINANCIAL_STATUS_PARTIALLY_REFUNDED,
                CommerceOrder::FINANCIAL_STATUS_REFUNDED,
                CommerceOrder::FINANCIAL_STATUS_VOIDED,
            ],
            'fulfillment_statuses' => [
                CommerceOrder::FULFILLMENT_STATUS_UNFULFILLED,
                CommerceOrder::FULFILLMENT_STATUS_PARTIAL,
                CommerceOrder::FULFILLMENT_STATUS_FULFILLED,
                CommerceOrder::FULFILLMENT_STATUS_RESTOCKED,
            ],
        ];
    }

    public function canView(User $user, CommerceOrder $order): bool
    {
        $contactId = $order->contact_id;

        if ($contactId === null) {
            $customer = $order->relationLoaded('commerceCustomer')
                ? $order->commerceCustomer
                : $order->commerceCustomer()->first();
            $contactId = $customer?->contact_id;
        }

        if ($contactId !== null) {
            $contact = $order->relationLoaded('contact')
                && (int) $order->contact_id === (int) $contactId
                    ? $order->contact
                    : Contact::query()->find($contactId);

            return $contact instanceof Contact
                && $this->contactVisibility->canView($user, $contact);
        }

        return $this->access->isActive($user)
            && $this->access->allows($user, 'contacts.view_all');
    }

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $filters */
    private function filteredOrdersQuery(User $user, array $filters): Builder
    {
        $filters = $this->filters($filters);
        $query = $this->visibleOrdersQuery($user);

        if ($filters['q'] !== null) {
            $search = $filters['q'];
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('order_name', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%")
                    ->orWhereHas('contact', static fn (Builder $contactQuery) => $contactQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('commerceCustomer', static fn (Builder $customerQuery) => $customerQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%"));
            });
        }

        if ($filters['financial_status'] !== null) {
            $query->where('financial_status', $filters['financial_status']);
        }

        if ($filters['fulfillment_status'] !== null) {
            $query->where('fulfillment_status', $filters['fulfillment_status']);
        }

        if ($filters['provider'] !== null) {
            $query->where('provider', $filters['provider']);
        }

        if ($filters['confirmation'] === 'confirmed') {
            $query->whereHas('events', static fn (Builder $eventQuery) => $eventQuery
                ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                ->where('source', 'commerce'));
        } elseif ($filters['confirmation'] === 'unconfirmed') {
            $query->whereDoesntHave('events', static fn (Builder $eventQuery) => $eventQuery
                ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                ->where('source', 'commerce'));
        }

        return $query;
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
                $query
                    ->whereIn('contact_id', clone $visibleContactIds)
                    ->orWhere(function (Builder $fallback) use ($visibleContactIds): void {
                        $fallback
                            ->whereNull('contact_id')
                            ->whereHas('commerceCustomer', static fn (Builder $customerQuery) => $customerQuery
                                ->whereIn('contact_id', clone $visibleContactIds));
                    });

                if ($mayViewUnlinked) {
                    $query->orWhereNull('contact_id');
                }
            });
    }

    /** @return array{state:string,event:?CommerceOrderEvent,confirmation:?CommercePurchaseConfirmation,error:?string} */
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

    /** @return Collection<int, CommerceInventoryEffect> */
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