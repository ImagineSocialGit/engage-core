<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Enums\CommerceStorefrontView;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

final class CommerceStorefrontViewResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function resolve(CommerceStorefrontView $view): string
    {
        $configured = $this->config->get(
            "commerce.storefront.views.{$view->value}",
            $view->defaultView(),
        );

        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException(
                "Commerce storefront view [{$view->value}] must resolve to a non-empty Blade view name.",
            );
        }

        return trim($configured);
    }
}