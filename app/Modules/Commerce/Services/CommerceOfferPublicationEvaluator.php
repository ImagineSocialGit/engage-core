<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Data\CommerceOfferPublicationState;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOffer;
use App\Modules\Commerce\Models\CommerceOfferVariant;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use Illuminate\Support\Collection;
use RuntimeException;

final class CommerceOfferPublicationEvaluator
{
    public const REASON_INACTIVE = 'offer_inactive';
    public const REASON_NOT_STARTED = 'publication_not_started';
    public const REASON_ENDED = 'publication_ended';
    public const REASON_NO_ACTIVE_VARIANTS = 'no_active_variants';
    public const REASON_MULTIPLE_DEFAULTS = 'multiple_default_variants';
    public const REASON_PRICING_PROVIDER = 'pricing_provider_unavailable';
    public const REASON_CHECKOUT_PROVIDER = 'checkout_provider_unavailable';
    public const REASON_PROMOTION_PROVIDER = 'promotion_provider_unavailable';
    public const REASON_PROVIDER_MAPPINGS = 'no_provider_ready_variants';

    public function __construct(
        private readonly CommerceProviderRoleResolver $roles,
        private readonly CommerceProviderVariantReferenceResolver $references,
    ) {}

    public function evaluate(CommerceOffer $offer): CommerceOfferPublicationState
    {
        $offer->loadMissing([
            'offerVariants.commerceProductVariant.commerceProduct',
        ]);

        $reasons = [];
        $now = now();

        if ($offer->status !== CommerceOffer::STATUS_ACTIVE) {
            $reasons[] = self::REASON_INACTIVE;
        }

        if ($offer->publish_starts_at !== null && $offer->publish_starts_at->isAfter($now)) {
            $reasons[] = self::REASON_NOT_STARTED;
        }

        if ($offer->publish_ends_at !== null && ! $offer->publish_ends_at->isAfter($now)) {
            $reasons[] = self::REASON_ENDED;
        }

        $candidates = $this->activeCandidates($offer->offerVariants);

        if ($candidates->isEmpty()) {
            $reasons[] = self::REASON_NO_ACTIVE_VARIANTS;
        }

        if ($candidates->where('is_default', true)->count() > 1) {
            $reasons[] = self::REASON_MULTIPLE_DEFAULTS;
        }

        $providers = [];

        foreach ([
            [CommerceProviderRole::Pricing, self::REASON_PRICING_PROVIDER],
            [CommerceProviderRole::Checkout, self::REASON_CHECKOUT_PROVIDER],
        ] as [$role, $reason]) {
            try {
                $providers[$role->value] = $this->roles->resolve($role, $offer->provider_scope);
            } catch (RuntimeException) {
                $reasons[] = $reason;
            }
        }

        try {
            $promotionProvider = $this->roles->resolveOptional(
                CommerceProviderRole::Promotion,
                $offer->provider_scope,
            );
        } catch (RuntimeException) {
            $promotionProvider = null;
            $reasons[] = self::REASON_PROMOTION_PROVIDER;
        }

        if ($promotionProvider !== null) {
            $providers[CommerceProviderRole::Promotion->value] = $promotionProvider;
        }

        $readyVariantIds = [];

        if ($candidates->isNotEmpty()
            && ! in_array(self::REASON_PRICING_PROVIDER, $reasons, true)
            && ! in_array(self::REASON_CHECKOUT_PROVIDER, $reasons, true)
            && ! in_array(self::REASON_PROMOTION_PROVIDER, $reasons, true)
        ) {
            foreach ($candidates as $candidate) {
                $variant = $candidate->commerceProductVariant;

                if (! $variant instanceof CommerceProductVariant) {
                    continue;
                }

                try {
                    foreach ($providers as $provider) {
                        $this->references->resolve($variant, $provider->key());
                    }

                    $readyVariantIds[] = (int) $variant->getKey();
                } catch (RuntimeException) {
                    continue;
                }
            }

            if ($readyVariantIds === []) {
                $reasons[] = self::REASON_PROVIDER_MAPPINGS;
            }
        }

        return new CommerceOfferPublicationState(
            reasonCodes: array_values(array_unique($reasons)),
            readyVariantIds: array_values(array_unique($readyVariantIds)),
        );
    }

    /**
     * @param Collection<int, CommerceOfferVariant> $offerVariants
     * @return Collection<int, CommerceOfferVariant>
     */
    private function activeCandidates(Collection $offerVariants): Collection
    {
        return $offerVariants
            ->filter(static function (CommerceOfferVariant $offerVariant): bool {
                $variant = $offerVariant->commerceProductVariant;
                $product = $variant?->commerceProduct;

                return $offerVariant->status === CommerceOfferVariant::STATUS_ACTIVE
                    && $variant instanceof CommerceProductVariant
                    && $variant->status === CommerceProductVariant::STATUS_ACTIVE
                    && $product instanceof CommerceProduct
                    && $product->status === CommerceProduct::STATUS_ACTIVE;
            })
            ->sortBy([
                ['position', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }
}