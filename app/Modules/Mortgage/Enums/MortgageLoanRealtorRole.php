<?php

namespace App\Modules\Mortgage\Enums;

enum MortgageLoanRealtorRole: string
{
    case BuyerAgent = 'buyer_agent';
    case ListingAgent = 'listing_agent';
}