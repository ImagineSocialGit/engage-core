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
            'id' => ['Contact record number', 'The CRM record number for this contact.', null, [], false],
            'first_name' => ['First name', 'The contact’s first name.', 'Jamie', ['first_name'], true],
            'last_name' => ['Last name', 'The contact’s last name.', 'Morgan', ['last_name'], true],
            'name' => ['Full name', 'The contact’s full display name.', 'Jamie Morgan', ['name'], true],
            'email' => ['Email address', 'The contact’s primary email address.', 'jamie@example.com', ['email'], true],
            'phone' => ['Phone number', 'The contact’s primary phone number.', '(555) 123-4567', ['phone'], true],
            'birthday' => ['Birthday', 'The birthday stored on the contact.', 'August 31', ['birthday'], true],
            'source' => ['How they found us', 'The contact’s broad acquisition source.', 'Referral', [], true],
            'subsource' => ['Acquisition detail', 'A more specific internal acquisition detail.', 'Past-client referral', [], true],
            'created_at' => ['Date added', 'When the contact was first added to the CRM.', null, [], false],
            'updated_at' => ['Last updated date', 'When the contact record was most recently updated.', null, [], false],
        ];

        foreach ($definitions as $column => [$label, $description, $example, $aliases, $nullable]) {
            yield TokenSourceDefinition::modelColumn(
                token: "contact.{$column}",
                owner: 'core',
                label: $label,
                description: $description,
                modelClass: Contact::class,
                column: $column,
                aliases: $aliases,
                nullable: $nullable,
                example: $example,
            );
        }
    }
}