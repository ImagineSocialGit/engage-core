<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CommerceContactPurchaseHistoryReadService
{
    /**
     * @return array{
     *     order_count:int,
     *     confirmed_purchase_count:int,
     *     confirmed_values:Collection<int, array{currency:string,total_cents:int}>,
     *     latest_ordered_at:mixed,
     *     recent_orders:Collection<int, CommerceOrder>
     * }
     */
    public function forContact(Contact $contact, int $limit = 5): array
    {
        $orders = $this->ordersFor($contact);
        $confirmed = (clone $orders)
            ->whereHas('events', static fn (Builder $query) => $query
                ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                ->where('source', 'commerce'));

        $recentOrders = (clone $orders)
            ->with([
                'commerceCustomer',
                'items' => static fn ($query) => $query->orderBy('id'),
            ])
            ->withCount([
                'items',
                'events as purchase_confirmed_count' => static fn (Builder $query) => $query
                    ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
                    ->where('source', 'commerce'),
            ])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(max(1, min(20, $limit)))
            ->get();

        return [
            'order_count' => (clone $orders)->count(),
            'confirmed_purchase_count' => (clone $confirmed)->count(),
            'confirmed_values' => $this->confirmedValues($confirmed),
            'latest_ordered_at' => $recentOrders->first()?->ordered_at,
            'recent_orders' => $recentOrders,
        ];
    }

    private function ordersFor(Contact $contact): Builder
    {
        $contactId = (int) $contact->getKey();

        return CommerceOrder::query()
            ->where(function (Builder $query) use ($contactId): void {
                $query
                    ->where('contact_id', $contactId)
                    ->orWhere(function (Builder $fallback) use ($contactId): void {
                        $fallback
                            ->whereNull('contact_id')
                            ->whereHas('commerceCustomer', static fn (Builder $customerQuery) => $customerQuery
                                ->where('contact_id', $contactId));
                    });
            });
    }

    /**
     * @return Collection<int, array{currency:string,total_cents:int}>
     */
    private function confirmedValues(Builder $confirmed): Collection
    {
        return (clone $confirmed)
            ->whereNotNull('currency')
            ->where('currency', '!=', '')
            ->selectRaw('currency, SUM(total_cents) as total_cents')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(static fn (CommerceOrder $order): array => [
                'currency' => strtoupper((string) $order->currency),
                'total_cents' => (int) $order->total_cents,
            ])
            ->values();
    }
}