<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactNameNormalizer;
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

    public function __construct(
        private readonly ContactNameNormalizer $contactNameNormalizer,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function handle(Contact $contact, array $data): Contact
    {
        $editable = Arr::only($data, self::EDITABLE_FIELDS);
        $editable = $this->contactNameNormalizer->normalizeFields($editable);

        $contact->fill($editable);

        if ($contact->isDirty()) {
            $contact->save();
        }

        return $contact->refresh();
    }
}