<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactBirthdayFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_can_store_a_birthday_as_a_first_class_date(): void
    {
        $contact = Contact::query()->create([
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
}