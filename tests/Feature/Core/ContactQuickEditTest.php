<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactQuickEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_show_exposes_quick_edit_contracts(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.show', $contact));

        $response->assertOk();
        $response->assertSee('data-contact-quick-edit="name"', false);
        $response->assertSee('data-contact-quick-edit="email"', false);
        $response->assertSee('data-contact-quick-edit="phone"', false);
        $response->assertSee('data-contact-details-edit', false);
        $response->assertSee(route('crm.contacts.update', $contact), false);
    }

    public function test_name_can_be_updated_without_rewriting_other_contact_fields(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Jamie Morgan',
            'email' => 'jamie@example.test',
            'phone' => '5551112222',
        ]);

        $this
            ->actingAs($user)
            ->patch(route('crm.contacts.update', $contact), [
                'contact_edit_context' => 'name',
                'name' => 'Jamie M. Morgan',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $contact->refresh();

        $this->assertSame('Jamie M. Morgan', $contact->name);
        $this->assertSame('jamie@example.test', $contact->email);
        $this->assertSame('5551112222', $contact->phone);
    }

    public function test_email_is_normalized_and_must_remain_unique(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'old@example.test',
        ]);

        $this
            ->actingAs($user)
            ->patch(route('crm.contacts.update', $contact), [
                'contact_edit_context' => 'email',
                'email' => '  NEW@EXAMPLE.TEST  ',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertSame('new@example.test', $contact->refresh()->email);

        Contact::factory()->create([
            'email' => 'taken@example.test',
        ]);

        $this
            ->actingAs($user)
            ->from(route('crm.contacts.show', $contact))
            ->patch(route('crm.contacts.update', $contact), [
                'contact_edit_context' => 'email',
                'email' => 'TAKEN@example.test',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact))
            ->assertSessionHasErrors('email');

        $this->assertSame('new@example.test', $contact->refresh()->email);
    }

    public function test_phone_can_be_cleared_with_a_single_field_update(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'phone' => '5551112222',
        ]);

        $this
            ->actingAs($user)
            ->patch(route('crm.contacts.update', $contact), [
                'contact_edit_context' => 'phone',
                'phone' => '',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertNull($contact->refresh()->phone);
    }

    public function test_details_update_changes_generic_core_contact_fields_by_contact_id(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'first_name' => 'Jamie',
            'last_name' => 'Morgan',
            'name' => 'Jamie Morgan',
            'email' => 'jamie@example.test',
            'phone' => '5551112222',
            'source' => 'referral',
            'subsource' => 'past_client',
        ]);

        $this
            ->actingAs($user)
            ->patch(route('crm.contacts.update', $contact), [
                'contact_edit_context' => 'details',
                'first_name' => 'James',
                'last_name' => 'Morgan',
                'name' => 'James Morgan',
                'email' => 'james@example.test',
                'phone' => '5553334444',
                'birthday' => '1987-04-12',
                'source' => 'website',
                'subsource' => 'contact_form',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $contact->refresh();

        $this->assertSame('James', $contact->first_name);
        $this->assertSame('Morgan', $contact->last_name);
        $this->assertSame('James Morgan', $contact->name);
        $this->assertSame('james@example.test', $contact->email);
        $this->assertSame('5553334444', $contact->phone);
        $this->assertSame('1987-04-12', $contact->birthday?->toDateString());
        $this->assertSame('website', $contact->source);
        $this->assertSame('contact_form', $contact->subsource);
        $this->assertSame(1, Contact::query()->count());
    }
}