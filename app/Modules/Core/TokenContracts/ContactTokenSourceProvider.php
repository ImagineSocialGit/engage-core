<?php

namespace App\Modules\Core\TokenContracts;

use App\Modules\Core\Models\Contact;
use App\Support\TokenContracts\Contracts\TokenSourceProvider;
use App\Support\TokenContracts\Data\TokenSourceDefinition;

class ContactTokenSourceProvider implements TokenSourceProvider
{
    public function sources(): iterable
    {
        $definitions = [
            'id' => ['Contact ID', [], false],
            'first_name' => ['First name', ['first_name'], true],
            'last_name' => ['Last name', ['last_name'], true],
            'name' => ['Full name', ['name'], true],
            'email' => ['Email address', ['email'], true],
            'phone' => ['Phone number', ['phone'], true],
            'source' => ['Contact source', [], true],
            'subsource' => ['Contact subsource', [], true],
            'created_at' => ['Created date', [], false],
            'updated_at' => ['Updated date', [], false],
        ];

        foreach ($definitions as $column => [$label, $aliases, $nullable]) {
            yield TokenSourceDefinition::modelColumn(
                token: "contact.{$column}",
                owner: 'core',
                label: $label,
                description: "Value stored in the contacts.{$column} column.",
                modelClass: Contact::class,
                column: $column,
                aliases: $aliases,
                nullable: $nullable,
            );
        }
    }
}
