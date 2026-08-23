<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactBirthdayFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_can_store_birthday_through_the_core_update_seam(): void
    {
        $contact = app(CreateOrUpdateContactAction::class)->handle([
            'first_name' => 'Jamie',
            'email' => 'jamie@example.test',
            'birthday' => '1987-04-12',
        ]);

        $contact->refresh();

        $this->assertSame('1987-04-12', $contact->birthday?->toDateString());
        $this->assertDatabaseHas('contacts', [
            'id' => $contact->getKey(),
            'birthday' => '1987-04-12',
        ]);
    }

    public function test_birthday_is_available_as_a_generic_core_import_field(): void
    {
        $field = collect(app(ContactImportRegistry::class)->fields())
            ->first(fn ($field): bool => $field->key === 'birthday');

        $this->assertNotNull($field);
        $this->assertSame('birthday', $field->contactAttribute);
    }
}