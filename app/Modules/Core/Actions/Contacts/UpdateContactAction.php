<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Models\Contact;
use Illuminate\Support\Arr;

final class UpdateContactAction
{
    private const EDITABLE_FIELDS = [
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'birthday',
        'source',
        'subsource',
    ];

    /**
     * @param array<string, mixed> $data
     */
    public function handle(Contact $contact, array $data): Contact
    {
        $contact->fill(Arr::only($data, self::EDITABLE_FIELDS));

        if ($contact->isDirty()) {
            $contact->save();
        }

        return $contact->refresh();
    }
}