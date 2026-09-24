<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommercePricingProvider;
use App\Modules\Commerce\Contracts\CommercePromotionProvider;
use App\Modules\Commerce\Data\CommerceStorefrontState;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOffer;
use App\Modules\Commerce\Models\CommerceOfferVariant;
use App\Modules\Commerce\Models\CommerceProductVariant;
use RuntimeException;

final class CommerceStorefrontStateResolver
{
    public function __construct(
        private readonly CommerceOfferPublicationEvaluator $publication,
        private readonly CommerceProviderRoleResolver $roles,
        private readonly CommerceProviderVariantReferenceResolver $references,
    ) {}

    public function resolve(
        CommerceOffer $offer,
        ?int $commerceProductVariantId = null,
    ): CommerceStorefrontState {
        $publication = $this->publication->evaluate($offer);

        if (! $publication->eligible()) {
            throw new RuntimeException('Commerce offer is not eligible for storefront presentation.');
        }

        $offer->loadMissing('offerVariants.commerceProductVariant');
        $offerVariant = $this->selectOfferVariant(
            $offer,
            $publication->readyVariantIds,
            $commerceProductVariantId,
        );
        $variant = $offerVariant->commerceProductVariant;

        if (! $variant instanceof CommerceProductVariant) {
            throw new RuntimeException('Commerce offer variant has no canonical product variant.');
        }

        $pricingProvider = $this->roles->resolve(
            CommerceProviderRole::Pricing,
            $offer->provider_scope,
        );

        if (! $pricingProvider instanceof CommercePricingProvider) {
            throw new RuntimeException('Configured Commerce pricing provider is invalid.');
        }

        $pricing = $pricingProvider->pricing(
            $this->references->resolve($variant, $pricingProvider->key()),
            $offer->provider_scope,
        );

        if ($pricing->providerKey !== $pricingProvider->key()) {
            throw new RuntimeException('Commerce pricing state provider identity does not match the resolved provider.');
        }

        $promotion = null;
        $promotionProvider = $this->roles->resolveOptional(
            CommerceProviderRole::Promotion,
            $offer->provider_scope,
        );

        if ($promotionProvider !== null) {
            if (! $promotionProvider instanceof CommercePromotionProvider) {
                throw new RuntimeException('Configured Commerce promotion provider is invalid.');
            }

            $promotion = $promotionProvider->promotion(
                $this->references->resolve($variant, $promotionProvider->key()),
                $offer->provider_scope,
            );

            if ($promotion->providerKey !== $promotionProvider->key()) {
                throw new RuntimeException('Commerce promotion state provider identity does not match the resolved provider.');
            }
        }

        $checkoutProvider = $this->roles->resolve(
            CommerceProviderRole::Checkout,
            $offer->provider_scope,
        );

        return new CommerceStorefrontState(
            offer: $offer,
            offerVariant: $offerVariant,
            pricing: $pricing,
            promotion: $promotion,
            checkoutProviderKey: $checkoutProvider->key(),
        );
    }

    /** @param array<int, int> $readyVariantIds */
    private function selectOfferVariant(
        CommerceOffer $offer,
        array $readyVariantIds,
        ?int $commerceProductVariantId,
    ): CommerceOfferVariant {
        $candidates = $offer->offerVariants
            ->filter(static fn (CommerceOfferVariant $offerVariant): bool => in_array(
                (int) $offerVariant->commerce_product_variant_id,
                $readyVariantIds,
                true,
            ))
            ->sortBy([
                ['position', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        if ($commerceProductVariantId !== null) {
            $selected = $candidates->firstWhere(
                'commerce_product_variant_id',
                $commerceProductVariantId,
            );

            if (! $selected instanceof CommerceOfferVariant) {
                throw new RuntimeException('Requested Commerce variant is not available through this offer.');
            }

            return $selected;
        }

        $default = $candidates->firstWhere('is_default', true);

        if ($default instanceof CommerceOfferVariant) {
            return $default;
        }

        $selected = $candidates->first();

        if (! $selected instanceof CommerceOfferVariant) {
            throw new RuntimeException('Commerce offer has no provider-ready variant.');
        }

        return $selected;
    }
}