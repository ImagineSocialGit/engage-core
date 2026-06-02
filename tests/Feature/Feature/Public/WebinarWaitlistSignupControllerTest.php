<?php

namespace Tests\Feature\Public;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\Contact;
use App\Models\WebinarSeries;
use App\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarWaitlistSignupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_waitlist_signup_is_stored_successfully(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'first-time-homebuyer',
        ]);

        $response = $this->post(route('webinar.waitlist.store', $series->slug), [
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@gmail.com',
            'phone' => '6155551212',
            'transactional_email_consent' => true,
        ]);

        $response->assertRedirect(route('webinar.show', $series->slug));

        $contact = Contact::query()->where('email', 'jeff@gmail.com')->first();

        $this->assertNotNull($contact);

        $this->assertDatabaseHas('webinar_waitlist_signups', [
            'contact_id' => $contact->id,
            'webinar_series_id' => $series->id,
        ]);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'source' => 'webinar_waitlist',
        ]);
    }

    public function test_duplicate_waitlist_signup_updates_existing_record(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
        ]);

        $contact = Contact::query()->create([
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'jeff@gmail.com',
            'phone' => '6150000000',
            'source' => 'webinar_waitlist',
        ]);

        $signup = WebinarWaitlistSignup::factory()->create([
            'contact_id' => $contact->id,
            'webinar_series_id' => $series->id,
        ]);

        $this->post(route('webinar.waitlist.store', $series->slug), [
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@gmail.com',
            'phone' => '6155551212',
            'transactional_email_consent' => true,
        ])->assertRedirect(route('webinar.show', $series->slug));

        $this->assertDatabaseCount('webinar_waitlist_signups', 1);

        $signup->refresh();

        $this->assertSame($contact->id, $signup->contact_id);
    }

    public function test_waitlist_signup_requires_at_least_one_transactional_channel(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(route('webinar.waitlist.store', $series->slug), [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@gmail.com',
                'phone' => '6155551212',
            ]);

        $response
            ->assertRedirect(route('webinar.show', $series->slug))
            ->assertSessionHasErrors([
                'transactional_consent',
            ]);

        $this->assertDatabaseCount('webinar_waitlist_signups', 0);
        $this->assertDatabaseCount('message_consents', 0);
    }

    public function test_waitlist_signup_can_use_transactional_sms_consent_only(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
        ]);

        $this->post(route('webinar.waitlist.store', $series->slug), [
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@gmail.com',
            'phone' => '6155551212',
            'transactional_sms_consent' => true,
        ])->assertRedirect(route('webinar.show', $series->slug));

        $contact = Contact::query()->where('email', 'jeff@gmail.com')->first();

        $this->assertNotNull($contact);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Sms->value,
            'purpose' => MessagePurpose::Transactional->value,
            'source' => 'webinar_waitlist',
        ]);

        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
        ]);
    }

    public function test_waitlist_signup_can_use_both_transactional_channels(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
        ]);

        $this->post(route('webinar.waitlist.store', $series->slug), [
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@gmail.com',
            'phone' => '6155551212',
            'transactional_email_consent' => true,
            'transactional_sms_consent' => true,
        ])->assertRedirect(route('webinar.show', $series->slug));

        $contact = Contact::query()->where('email', 'jeff@gmail.com')->first();

        $this->assertNotNull($contact);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'source' => 'webinar_waitlist',
        ]);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Sms->value,
            'purpose' => MessagePurpose::Transactional->value,
            'source' => 'webinar_waitlist',
        ]);
    }
}