<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommerceCheckoutProvider;
use App\Modules\Commerce\Data\CommerceCheckoutRequest;
use App\Modules\Commerce\Data\CommerceCheckoutResult;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOffer;
use App\Modules\Commerce\Models\CommerceProductVariant;
use RuntimeException;

final class CommerceCheckoutService
{
    public function __construct(
        private readonly CommerceStorefrontStateResolver $storefront,
        private readonly CommerceProviderRoleResolver $roles,
        private readonly CommerceProviderVariantReferenceResolver $references,
    ) {}

    public function start(
        CommerceOffer $offer,
        ?int $commerceProductVariantId,
        string $quantity,
        string $successUrl,
        string $cancelUrl,
    ): CommerceCheckoutResult {
        $state = $this->storefront->resolve($offer, $commerceProductVariantId);

        if (! $state->canCheckout()) {
            throw new RuntimeException('Commerce storefront state is not currently checkout-eligible.');
        }

        $variant = $state->offerVariant->commerceProductVariant;

        if (! $variant instanceof CommerceProductVariant) {
            throw new RuntimeException('Commerce checkout requires a canonical product variant.');
        }

        $provider = $this->roles->resolve(
            CommerceProviderRole::Checkout,
            $offer->provider_scope,
        );

        if (! $provider instanceof CommerceCheckoutProvider) {
            throw new RuntimeException('Configured Commerce checkout provider is invalid.');
        }

        $result = $provider->createCheckout(new CommerceCheckoutRequest(
            commerceOfferId: (int) $offer->getKey(),
            variant: $this->references->resolve($variant, $provider->key()),
            quantity: $quantity,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            promotion: $state->promotion?->active === true
                ? $state->promotion
                : null,
        ));

        if ($result->providerKey !== $provider->key()) {
            throw new RuntimeException('Commerce checkout result provider identity does not match the resolved provider.');
        }

        return $result;
    }
}