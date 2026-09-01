<?php

namespace App\Modules\Core\ModuleFacts;

use App\Modules\Core\Models\Contact;
use App\Support\ModuleFacts\Contracts\ModuleFactProvider;
use App\Support\ModuleFacts\Data\ModuleFactDefinition;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;

class CoreContactModuleFactProvider implements ModuleFactProvider
{
    public function __construct(
        private readonly ContactBirthdayModuleFactResolver $birthday,
    ) {}

    public function facts(): iterable
    {
        yield new ModuleFactDefinition(
            key: 'core.contact.birthday',
            owner: 'core',
            label: 'Contact birthday',
            description: 'The birthday saved on the Contact record.',
            subject: Contact::class,
            type: ModuleFactType::Date,
            capabilities: [
                ModuleFactCapability::Renderable,
                ModuleFactCapability::Filterable,
                ModuleFactCapability::Annualizable,
                ModuleFactCapability::Writable,
            ],
            valueResolver: $this->birthday,
            queryResolver: $this->birthday,
            aliases: ['birthday', 'contact.birthday'],
        );
    }
}