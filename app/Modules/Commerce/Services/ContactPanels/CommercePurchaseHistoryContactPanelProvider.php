<?php

namespace App\Modules\Commerce\Services\ContactPanels;

use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Services\CommerceContactPurchaseHistoryReadService;
use App\Modules\Core\Contracts\Contacts\ContactPanelProvider;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CommercePurchaseHistoryContactPanelProvider implements ContactPanelProvider
{
    public function __construct(
        private readonly CommerceContactPurchaseHistoryReadService $history,
    ) {}

    public function panels(Contact $contact): array
    {
        $history = $this->history->forContact($contact);

        if ($history['order_count'] < 1) {
            return [];
        }

        return [
            new ContactPanel(
                key: 'commerce-purchase-history',
                title: 'Purchase History',
                view: 'crm.contacts.panels.commerce-purchase-history',
                data: [
                    'purchaseHistory' => [
                        'description' => 'Orders reconciled to this '.strtolower((string) config('contacts.labels.singular', 'contact')).'.',
                        'order_count' => $history['order_count'],
                        'order_count_label' => number_format($history['order_count']).' '.Str::plural('order', $history['order_count']),
                        'confirmed_purchase_count' => $history['confirmed_purchase_count'],
                        'confirmed_count_label' => number_format($history['confirmed_purchase_count']).' confirmed',
                        'confirmed_values' => $history['confirmed_values']
                            ->map(static fn (array $value): array => [
                                ...$value,
                                'label' => sprintf(
                                    '%s %s',
                                    $value['currency'],
                                    number_format($value['total_cents'] / 100, 2),
                                ),
                            ])
                            ->values(),
                        'recent_orders' => $this->presentOrders($history['recent_orders']),
                        'truncation_note' => $history['order_count'] > $history['recent_orders']->count()
                            ? 'Showing the '.number_format($history['recent_orders']->count()).' most recent orders. Open an order above for full lifecycle, purchase-confirmation, and inventory evidence.'
                            : null,
                    ],
                ],
                sort: 80,
                module: 'commerce',
            ),
        ];
    }

    /**
     * @param Collection<int, CommerceOrder> $orders
     * @return Collection<int, array<string, mixed>>
     */
    private function presentOrders(Collection $orders): Collection
    {
        $timezone = config(
            'client.timezone',
            config('app.timezone', 'UTC'),
        );

        return $orders
            ->map(static function (CommerceOrder $order) use ($timezone): array {
                $items = $order->items
                    ->map(static fn ($item): string => trim((string) (
                        $item->name
                        ?: $item->title
                        ?: $item->sku
                        ?: 'Item'
                    )))
                    ->filter()
                    ->values();
                $itemSummary = $items->take(3)->join(' · ');

                if ($items->count() > 3) {
                    $itemSummary .= ' · +'.($items->count() - 3).' more';
                }

                return [
                    'id' => (int) $order->getKey(),
                    'label' => $order->order_name
                        ?: $order->order_number
                        ?: 'Order #'.$order->getKey(),
                    'url' => route('crm.commerce.orders.show', $order),
                    'purchase_confirmed' => (int) $order->purchase_confirmed_count > 0,
                    'ordered_at_label' => $order->ordered_at?->timezone($timezone)->format('M j, Y g:i A')
                        ?? 'Order date unavailable',
                    'provider' => filled($order->provider) ? (string) $order->provider : null,
                    'item_summary' => $itemSummary !== '' ? $itemSummary : null,
                    'total_label' => sprintf(
                        '%s%s',
                        filled($order->currency) ? strtoupper((string) $order->currency).' ' : '',
                        number_format(((int) $order->total_cents) / 100, 2),
                    ),
                    'financial_status_label' => Str::of(
                        (string) ($order->financial_status ?: $order->status),
                    )->replace('_', ' ')->title()->toString(),
                    'fulfillment_status_label' => filled($order->fulfillment_status)
                        ? Str::of((string) $order->fulfillment_status)
                            ->replace('_', ' ')
                            ->title()
                            ->toString()
                        : null,
                ];
            })
            ->values();
    }
}