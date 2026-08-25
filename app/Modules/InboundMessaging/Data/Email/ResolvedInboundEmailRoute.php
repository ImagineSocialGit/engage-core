<?php

namespace App\Modules\InboundMessaging\Data\Email;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;

final readonly class ResolvedInboundEmailRoute
{
    public function __construct(
        public InboundEmailRoute $route,
        public string $address,
    ) {}
}