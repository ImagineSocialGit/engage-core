<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceCheckoutProvider;
use App\Modules\Commerce\Contracts\CommercePricingProvider;
use App\Modules\Commerce\Contracts\CommercePromotionProvider;
use App\Modules\Commerce\Data\CommerceCheckoutRequest;
use App\Modules\Commerce\Data\CommerceCheckoutResult;
use App\Modules\Commerce\Data\CommercePricingState;
use App\Modules\Commerce\Data\CommercePromotionState;
use App\Modules\Commerce\Data\CommerceProviderVariantReference;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Enums\CommerceStorefrontView;
use App\Modules\Commerce\Models\CommerceOffer;
use App\Modules\Commerce\Models\CommerceOfferVariant;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Services\CommerceCheckoutService;
use App\Modules\Commerce\Services\CommerceOfferPublicationEvaluator;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use App\Modules\Commerce\Services\CommerceProviderVariantReferenceResolver;
use App\Modules\Commerce\Services\CommerceStorefrontStateResolver;
use App\Modules\Commerce\Services\CommerceStorefrontViewResolver;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CommerceStorefrontFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_becomes_provider_ready_only_when_one_canonical_variant_has_required_explicit_mappings(): void
    {
        [$offer, $variant] = $this->offerWithVariant();
        $pricing = new StorefrontPricingTestProvider('pricing-provider');
        $checkout = new StorefrontCheckoutTestProvider('checkout-provider');
        $resolver = $this->roleResolver([$pricing, $checkout], [
            CommerceProviderRole::Pricing->value => 'pricing-provider',
            CommerceProviderRole::Checkout->value => 'checkout-provider',
        ]);
        $references = new CommerceProviderVariantReferenceResolver();
        $publication = new CommerceOfferPublicationEvaluator($resolver, $references);

        $this->mapVariant($variant, 'pricing-provider', 'pricing-variant');
        $this->mapVariant($variant, 'checkout-provider', 'checkout-variant');

        $ready = $publication->evaluate($offer);

        $this->assertTrue($ready->eligible());
        $this->assertSame([(int) $variant->getKey()], $ready->readyVariantIds);

        CommerceProductVariantProviderMapping::query()
            ->where('commerce_product_variant_id', $variant->getKey())
            ->where('provider_key', 'checkout-provider')
            ->update(['status' => CommerceProductVariantProviderMapping::STATUS_INACTIVE]);

        $blocked = $publication->evaluate($offer->fresh());

        $this->assertFalse($blocked->eligible());
        $this->assertContains(
            CommerceOfferPublicationEvaluator::REASON_PROVIDER_MAPPINGS,
            $blocked->reasonCodes,
        );
    }

    public function test_storefront_pricing_promotion_and_checkout_authorities_can_be_different_providers(): void
    {
        [$offer, $variant] = $this->offerWithVariant();
        $pricing = new StorefrontPricingTestProvider('pricing-provider');
        $promotion = new StorefrontPromotionTestProvider('promotion-provider');
        $checkout = new StorefrontCheckoutTestProvider('checkout-provider');
        $resolver = $this->roleResolver([$pricing, $promotion, $checkout], [
            CommerceProviderRole::Pricing->value => 'pricing-provider',
            CommerceProviderRole::Promotion->value => 'promotion-provider',
            CommerceProviderRole::Checkout->value => 'checkout-provider',
        ]);
        $references = new CommerceProviderVariantReferenceResolver();

        $this->mapVariant($variant, 'pricing-provider', 'pricing-variant');
        $this->mapVariant($variant, 'promotion-provider', 'promotion-variant');
        $this->mapVariant($variant, 'checkout-provider', 'checkout-variant');

        $publication = new CommerceOfferPublicationEvaluator($resolver, $references);
        $storefront = new CommerceStorefrontStateResolver(
            publication: $publication,
            roles: $resolver,
            references: $references,
        );
        $checkoutService = new CommerceCheckoutService(
            storefront: $storefront,
            roles: $resolver,
            references: $references,
        );

        $state = $storefront->resolve($offer);

        $this->assertSame('pricing-provider', $state->pricing->providerKey);
        $this->assertTrue($state->pricing->onSale());
        $this->assertSame('promotion-provider', $state->promotion?->providerKey);
        $this->assertSame('checkout-provider', $state->checkoutProviderKey);
        $this->assertSame('pricing-provider', $pricing->lastVariant?->providerKey);
        $this->assertSame('promotion-provider', $promotion->lastVariant?->providerKey);

        $result = $checkoutService->start(
            offer: $offer,
            commerceProductVariantId: (int) $variant->getKey(),
            quantity: '1',
            successUrl: 'https://shop.example.test/checkout/complete',
            cancelUrl: 'https://shop.example.test/offers/example-offer',
        );

        $this->assertSame('checkout-provider', $result->providerKey);
        $this->assertSame('checkout-provider', $checkout->lastRequest?->variant->providerKey);
        $this->assertSame(
            'checkout-variant',
            $checkout->lastRequest?->variant->reference('catalog_variant')?->externalId,
        );
        $this->assertSame('promotion-provider', $checkout->lastRequest?->promotion?->providerKey);
        $this->assertSame(
            ['promotion_token' => 'provider-promotion-token'],
            $checkout->lastRequest?->promotion?->checkoutContext,
        );
    }

    public function test_checkout_does_not_start_when_authoritative_pricing_marks_variant_unavailable(): void
    {
        [$offer, $variant] = $this->offerWithVariant();
        $pricing = new StorefrontPricingTestProvider('pricing-provider', availableForSale: false);
        $checkout = new StorefrontCheckoutTestProvider('checkout-provider');
        $resolver = $this->roleResolver([$pricing, $checkout], [
            CommerceProviderRole::Pricing->value => 'pricing-provider',
            CommerceProviderRole::Checkout->value => 'checkout-provider',
        ]);
        $references = new CommerceProviderVariantReferenceResolver();

        $this->mapVariant($variant, 'pricing-provider', 'pricing-variant');
        $this->mapVariant($variant, 'checkout-provider', 'checkout-variant');

        $storefront = new CommerceStorefrontStateResolver(
            publication: new CommerceOfferPublicationEvaluator($resolver, $references),
            roles: $resolver,
            references: $references,
        );
        $service = new CommerceCheckoutService($storefront, $resolver, $references);

        $this->expectException(RuntimeException::class);

        $service->start(
            offer: $offer,
            commerceProductVariantId: (int) $variant->getKey(),
            quantity: '1',
            successUrl: 'https://shop.example.test/checkout/complete',
            cancelUrl: 'https://shop.example.test/offers/example-offer',
        );
    }

    public function test_client_storefront_view_override_can_replace_one_surface_without_replacing_defaults(): void
    {
        $resolver = new CommerceStorefrontViewResolver(new Repository([
            'commerce' => [
                'storefront' => [
                    'views' => [
                        CommerceStorefrontView::Offer->value => 'client.commerce.offer',
                    ],
                ],
            ],
        ]));

        $this->assertSame(
            'client.commerce.offer',
            $resolver->resolve(CommerceStorefrontView::Offer),
        );
        $this->assertSame(
            CommerceStorefrontView::Home->defaultView(),
            $resolver->resolve(CommerceStorefrontView::Home),
        );
    }

    /** @return array{CommerceOffer, CommerceProductVariant} */
    private function offerWithVariant(): array
    {
        $product = CommerceProduct::factory()->active()->create();
        $variant = CommerceProductVariant::factory()->active()->for($product, 'commerceProduct')->create();
        $offer = CommerceOffer::factory()->active()->create([
            'key' => 'example-offer',
            'slug' => 'example-offer',
        ]);

        CommerceOfferVariant::factory()->default()->create([
            'commerce_offer_id' => $offer->getKey(),
            'commerce_product_variant_id' => $variant->getKey(),
        ]);

        return [$offer, $variant];
    }

    private function mapVariant(
        CommerceProductVariant $variant,
        string $providerKey,
        string $externalId,
    ): CommerceProductVariantProviderMapping {
        return CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => $providerKey,
            'reference_type' => 'catalog_variant',
            'external_id' => $externalId,
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param array<int, CommercePricingProvider|CommercePromotionProvider|CommerceCheckoutProvider> $providers
     * @param array<string, string> $bindings
     */
    private function roleResolver(array $providers, array $bindings): CommerceProviderRoleResolver
    {
        $configuration = [];

        foreach ($bindings as $role => $providerKey) {
            $configuration[$role] = [
                'default' => $providerKey,
                'scopes' => [],
            ];
        }

        return new CommerceProviderRoleResolver(
            providers: new CommerceProviderRegistry($providers),
            config: new Repository([
                'commerce' => [
                    'provider_roles' => $configuration,
                ],
            ]),
        );
    }
}

final class StorefrontPricingTestProvider implements CommercePricingProvider
{
    public ?CommerceProviderVariantReference $lastVariant = null;

    public function __construct(
        private readonly string $providerKey,
        private readonly bool $availableForSale = true,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function pricing(
        CommerceProviderVariantReference $variant,
        ?string $scope = null,
    ): CommercePricingState {
        $this->lastVariant = $variant;

        return new CommercePricingState(
            providerKey: $this->providerKey,
            currency: 'USD',
            unitPriceCents: 2000,
            compareAtPriceCents: 2500,
            availableForSale: $this->availableForSale,
            refreshedAt: new DateTimeImmutable(),
        );
    }
}

final class StorefrontPromotionTestProvider implements CommercePromotionProvider
{
    public ?CommerceProviderVariantReference $lastVariant = null;

    public function __construct(
        private readonly string $providerKey,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function promotion(
        CommerceProviderVariantReference $variant,
        ?string $scope = null,
    ): CommercePromotionState {
        $this->lastVariant = $variant;

        return new CommercePromotionState(
            providerKey: $this->providerKey,
            active: true,
            providerReference: 'promotion-1001',
            checkoutContext: [
                'promotion_token' => 'provider-promotion-token',
            ],
            refreshedAt: new DateTimeImmutable(),
        );
    }
}

final class StorefrontCheckoutTestProvider implements CommerceCheckoutProvider
{
    public ?CommerceCheckoutRequest $lastRequest = null;

    public function __construct(
        private readonly string $providerKey,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function createCheckout(
        CommerceCheckoutRequest $request,
    ): CommerceCheckoutResult {
        $this->lastRequest = $request;

        return new CommerceCheckoutResult(
            providerKey: $this->providerKey,
            externalCheckoutId: 'checkout-1001',
            nextUrl: 'https://checkout.example.test/session/checkout-1001',
            expiresAt: new DateTimeImmutable('+30 minutes'),
        );
    }
}