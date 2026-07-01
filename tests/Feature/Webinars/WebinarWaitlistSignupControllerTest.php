<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarWaitlistSignupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Config::set('cache-keys.enabled', false);

        Config::set('messaging.email.from.marketing', [
            'address' => 'marketing@engagecore.test',
            'name' => 'Engage Core Marketing',
        ]);

        Config::set('messaging.email.providers.resend.from.marketing', [
            'address' => 'marketing@engagecore.test',
            'name' => 'Engage Core Marketing',
        ]);

        $this->configureWebinarWaitlistChannelAvailability();
    }

    public function test_notify_me_page_hides_sms_when_sms_is_not_available_for_waitlists(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
            'title' => 'Homebuyer Basics',
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();
        $response->assertSee('name="marketing_email_consent"', false);
        $response->assertDontSee('name="marketing_sms_consent"', false);
        $response->assertDontSee('name="phone"', false);
        $response->assertDontSee('name="transactional_email_consent"', false);
        $response->assertDontSee('name="transactional_sms_consent"', false);
    }

    public function test_notify_me_page_shows_sms_when_sms_is_available_for_waitlists(): void
    {
        $this->enableWebinarWaitlistSms();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
            'title' => 'Homebuyer Basics',
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();
        $response->assertSee('name="marketing_email_consent"', false);
        $response->assertSee('name="marketing_sms_consent"', false);
        $response->assertSee('name="phone"', false);
    }

    public function test_it_creates_email_waitlist_signup_and_marketing_consent(): void
    {
        Queue::fake();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
            'title' => 'Homebuyer Basics',
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(route('webinar.waitlist.store', $series->slug), [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'JEFF@example.com',
                'marketing_email_consent' => true,
                'marketing_sms_consent' => false,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasNoErrors();

        $contact = Contact::query()->where('email', 'jeff@example.com')->first();

        $this->assertNotNull($contact);
        $this->assertSame('webinar_waitlist', $contact->source);
        $this->assertSame($series->slug, $contact->subsource);

        $signup = WebinarWaitlistSignup::query()->first();

        $this->assertNotNull($signup);
        $this->assertSame($contact->id, $signup->contact_id);
        $this->assertSame($series->id, $signup->webinar_series_id);
        $this->assertSame(['email'], $signup->meta['accepted_channels']['marketing']);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar_waitlist',
            'source' => 'webinar_waitlist',
        ]);

        $this->assertSame(1, MessageConsent::query()->count());
    }

    public function test_it_rejects_sms_consent_when_sms_is_hidden_for_waitlists(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(route('webinar.waitlist.store', $series->slug), [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '+15555550123',
                'marketing_email_consent' => false,
                'marketing_sms_consent' => true,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('marketing_sms_consent');

        $this->assertSame(0, WebinarWaitlistSignup::query()->count());
        $this->assertSame(0, MessageConsent::query()->count());
    }

    public function test_it_requires_phone_when_sms_consent_is_selected(): void
    {
        $this->enableWebinarWaitlistSms();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(route('webinar.waitlist.store', $series->slug), [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'marketing_email_consent' => false,
                'marketing_sms_consent' => true,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('phone');

        $this->assertSame(0, WebinarWaitlistSignup::query()->count());
        $this->assertSame(0, MessageConsent::query()->count());
    }

    public function test_it_creates_sms_waitlist_signup_and_consent_when_sms_is_available(): void
    {
        Queue::fake();

        $this->enableWebinarWaitlistSms();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
            'title' => 'Homebuyer Basics',
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(route('webinar.waitlist.store', $series->slug), [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '(555) 555-0123',
                'marketing_email_consent' => true,
                'marketing_sms_consent' => true,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasNoErrors();

        $contact = Contact::query()->where('email', 'jeff@example.com')->first();
        $signup = WebinarWaitlistSignup::query()->first();

        $this->assertNotNull($contact);
        $this->assertNotNull($signup);

        $this->assertSame('+15555550123', $contact->phone);
        $this->assertSame(['email', 'sms'], $signup->meta['accepted_channels']['marketing']);

        foreach ([MessageChannel::Email->value, MessageChannel::Sms->value] as $channel) {
            $this->assertDatabaseHas('message_consents', [
                'contact_id' => $contact->id,
                'channel' => $channel,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'webinar_waitlist',
                'source' => 'webinar_waitlist',
            ]);
        }

        $this->assertSame(2, MessageConsent::query()->count());
    }

    public function test_it_updates_existing_signup_without_creating_duplicate(): void
    {
        Queue::fake();
        
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        $payload = [
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'marketing_email_consent' => true,
            'marketing_sms_consent' => false,
        ];

        $this->post(route('webinar.waitlist.store', $series->slug), $payload)
            ->assertRedirect(route('webinar.show', $series->slug));

        $this->post(route('webinar.waitlist.store', $series->slug), array_replace($payload, [
            'first_name' => 'Jeffrey',
        ]))->assertRedirect(route('webinar.show', $series->slug));

        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(1, WebinarWaitlistSignup::query()->count());

        $contact = Contact::query()->first();

        $this->assertSame('Jeffrey', $contact->first_name);
    }

    private function configureWebinarWaitlistChannelAvailability(): void
    {
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_waitlists' => true,
            ],
            'purpose_scopes' => [
                'marketing:webinar_waitlist' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_waitlists' => false,
            ],
            'purpose_scopes' => [
                'marketing:webinar_waitlist' => true,
            ],
        ]);
    }

    private function enableWebinarWaitlistSms(): void
    {
        Config::set('messaging.channel_availability.sms.surfaces.webinar_waitlists', true);
    }
}
