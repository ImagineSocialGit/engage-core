<?php

namespace App\Modules\Mortgage\ModuleFacts;

use App\Modules\Core\Models\Contact;
use App\Support\ModuleFacts\Contracts\ModuleFactProvider;
use App\Support\ModuleFacts\Data\ModuleFactDefinition;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;

class MortgageModuleFactProvider implements ModuleFactProvider
{
    public function __construct(
        private readonly MortgageHomePurchaseDateModuleFactResolver $homePurchaseDate,
    ) {}

    public function facts(): iterable
    {
        yield new ModuleFactDefinition(
            key: 'mortgage.contact.home_purchase_date',
            owner: 'mortgage',
            label: 'Home purchase date',
            description: 'The most recent recorded Purchase-loan closing date for this Contact. Refinances are excluded.',
            subject: Contact::class,
            type: ModuleFactType::Date,
            capabilities: [
                ModuleFactCapability::Renderable,
                ModuleFactCapability::Filterable,
                ModuleFactCapability::Annualizable,
            ],
            valueResolver: $this->homePurchaseDate,
            queryResolver: $this->homePurchaseDate,
            aliases: ['mortgage.home_purchase_date'],
        );
    }
}