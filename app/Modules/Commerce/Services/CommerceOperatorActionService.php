<?php

namespace App\Modules\Commerce\Services;

use App\Models\User;
use App\Modules\Commerce\Contracts\CommerceInventoryReadProvider;
use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Data\CommerceInventoryState;
use App\Modules\Commerce\Data\CommerceOrderSyncRequest;
use App\Modules\Commerce\Data\CommerceOrderSyncResult;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceProductVariant;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final class CommerceOperatorActionService
{
    public function __construct(
        private readonly CommerceOperatorAccessService $access,
        private readonly CommerceOrderReadService $orders,
        private readonly CommerceProviderRoleResolver $roles,
        private readonly CommerceProviderVariantReferenceResolver $references,
        private readonly CommerceOrderSyncService $orderSync,
    ) {}

    public function canOperate(?User $user): bool
    {
        return $this->access->canOperate($user);
    }

    /**
     * @throws AuthorizationException
     */
    public function refreshOrder(
        User $user,
        CommerceOrder $order,
    ): CommerceOrderSyncResult {
        $this->access->authorize($user);

        if (! $this->orders->canView($user, $order)) {
            throw new AuthorizationException();
        }

        $provider = $this->roles->resolve(CommerceProviderRole::Orders);

        if (! $provider instanceof CommerceOrderProvider) {
            throw new RuntimeException('Configured Commerce orders provider cannot read orders.');
        }

        $providerKey = trim($provider->key());
        $orderProvider = trim((string) $order->provider);

        if ($orderProvider === '' || $orderProvider !== $providerKey) {
            throw new RuntimeException(
                'This order is not owned by the currently configured Commerce orders provider.',
            );
        }

        $externalOrderId = trim((string) $order->external_id);

        if ($externalOrderId === '') {
            throw new RuntimeException('This order has no provider identity to refresh.');
        }

        return $this->orderSync->sync(new CommerceOrderSyncRequest(
            externalOrderId: $externalOrderId,
        ));
    }

    /**
     * @throws AuthorizationException
     */
    public function readInventory(
        User $user,
        CommerceProductVariant $variant,
    ): CommerceInventoryState {
        $this->access->authorize($user);

        $provider = $this->roles->resolve(CommerceProviderRole::Inventory);

        if (! $provider instanceof CommerceInventoryReadProvider) {
            throw new RuntimeException('Configured Commerce inventory provider cannot read inventory.');
        }

        $providerKey = trim($provider->key());
        $reference = $this->references->resolve($variant, $providerKey);
        $state = $provider->inventory($reference);

        if ($state->providerKey !== $providerKey
            || $state->commerceProductVariantId !== (int) $variant->getKey()
        ) {
            throw new RuntimeException(
                'Commerce inventory provider returned state for a different provider or variant identity.',
            );
        }

        return $state;
    }
}