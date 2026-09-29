<?php

namespace App\Modules\Commerce\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Services\CommerceOperatorActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class CommerceOperatorController extends Controller
{
    public function refreshOrder(
        Request $request,
        CommerceOrder $commerceOrder,
        CommerceOperatorActionService $operator,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $result = $operator->refreshOrder($user, $commerceOrder);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('crm.commerce.orders.show', $commerceOrder)
                ->with('commerce_action_error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.commerce.orders.show', $result->commerceOrderId)
            ->with('commerce_action_success', sprintf(
                'Order refreshed. %d item(s) created, %d changed, %d removed.',
                $result->itemsCreated,
                $result->itemsChanged,
                $result->itemsRemoved,
            ));
    }

    public function readInventory(
        Request $request,
        CommerceProduct $commerceProduct,
        CommerceProductVariant $commerceProductVariant,
        CommerceOperatorActionService $operator,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless(
            (int) $commerceProductVariant->commerce_product_id === (int) $commerceProduct->getKey(),
            404,
        );

        try {
            $state = $operator->readInventory($user, $commerceProductVariant);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('crm.commerce.products.show', $commerceProduct)
                ->with('commerce_action_error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.commerce.products.show', $commerceProduct)
            ->with('commerce_inventory_read', [
                'variant_id' => $state->commerceProductVariantId,
                'provider_key' => $state->providerKey,
                'tracked' => $state->tracked,
                'available_quantity' => $state->availableQuantity,
                'external_inventory_item_id' => $state->externalInventoryItemId,
                'external_location_id' => $state->externalLocationId,
            ]);
    }
}