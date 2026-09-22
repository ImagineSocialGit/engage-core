<?php

namespace App\Modules\Commerce\Enums;

use App\Modules\Commerce\Contracts\CommerceCatalogProvider;
use App\Modules\Commerce\Contracts\CommerceCheckoutProvider;
use App\Modules\Commerce\Contracts\CommerceFulfillmentProvider;
use App\Modules\Commerce\Contracts\CommerceInventoryProvider;
use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Contracts\CommercePaymentProvider;
use App\Modules\Commerce\Contracts\CommercePointOfSaleProvider;
use App\Modules\Commerce\Contracts\CommercePricingProvider;
use App\Modules\Commerce\Contracts\CommercePromotionProvider;

enum CommerceProviderRole: string
{
    case Catalog = 'catalog';
    case Pricing = 'pricing';
    case Promotion = 'promotion';
    case Checkout = 'checkout';
    case Payment = 'payment';
    case Orders = 'orders';
    case Inventory = 'inventory';
    case Fulfillment = 'fulfillment';
    case PointOfSale = 'point_of_sale';

    /** @return class-string */
    public function contract(): string
    {
        return match ($this) {
            self::Catalog => CommerceCatalogProvider::class,
            self::Pricing => CommercePricingProvider::class,
            self::Promotion => CommercePromotionProvider::class,
            self::Checkout => CommerceCheckoutProvider::class,
            self::Payment => CommercePaymentProvider::class,
            self::Orders => CommerceOrderProvider::class,
            self::Inventory => CommerceInventoryProvider::class,
            self::Fulfillment => CommerceFulfillmentProvider::class,
            self::PointOfSale => CommercePointOfSaleProvider::class,
        };
    }
}