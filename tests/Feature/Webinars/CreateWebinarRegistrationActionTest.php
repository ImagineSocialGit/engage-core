<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Actions\AddRegistrantToWebinarProviderAction;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CreateWebinarRegistrationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWebinarRegistrationChannelAvailability();
    }

    public function test_it_creates_registration_contact_email_consents_and_forwards_them_to_initial_message_planning(): void
    {
        $webinar = Webinar::factory()->create([
            'external_id' => null,
        ]);

        $dispatchRegistration = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $dispatchRegistration
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn (
                WebinarRegistration $registration,
                ?array $contextKeys,
                array $consentGrants,
            ): bool => $contextKeys === null
                && count($consentGrants) === 2
                && collect($consentGrants)->every(
                    fn (MessageConsentGrantResult $grant): bool => $grant->becameActive,
                ))
            ->andReturn([]);

        app()->instance(DispatchWebinarRegistrationMessagesAction::class, $dispatchRegistration);

        $standalone = Mockery::mock(DispatchConsentOptInMessageAction::class);
        $standalone->shouldReceive('handle')->never();
        app()->instance(DispatchConsentOptInMessageAction::class, $standalone);

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')->never();
        app()->instance(AddRegistrantToWebinarProviderAction::class, $provider);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '(555) 555-0123',
                'transactional_email_consent' => true,
                'marketing_email_consent' => true,
            ],
            request: $this->registrationRequest(),
            webinarSlug: $webinar,
        );

        $this->assertTrue($result->wasCreated());
        $registration = $result->registration;
        $registration->refresh();

        $this->assertInstanceOf(WebinarRegistration::class, $registration);

        $contact = Contact::query()->where('email', 'jeff@example.com')->firstOrFail();

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'email' => 'jeff@example.com',
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'source' => 'webinar',
            'subsource' => $webinar->slug,
        ]);
        $this->assertDatabaseMissing('contact_workflow_profiles', [
            'contact_id' => $contact->id,
        ]);
        $this->assertDatabaseHas('webinar_registrations', [
            'id' => $registration->id,
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('message_consents', 2);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
        ]);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
        ]);
        $this->assertSame(['email'], $registration->meta['accepted_channels']['transactional']);
        $this->assertSame(['email'], $registration->meta['accepted_channels']['marketing']);
    }

    public function test_it_creates_sms_consents_only_when_sms_is_available_and_selected(): void
    {
        $this->enableWebinarRegistrationSms();

        $webinar = Webinar::factory()->create([
            'external_id' => null,
        ]);

        $dispatchRegistration = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $dispatchRegistration
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn (
                WebinarRegistration $registration,
                ?array $contextKeys,
                array $consentGrants,
            ): bool => $contextKeys === null
                && count($consentGrants) === 4
                && collect($consentGrants)->every(
                    fn (MessageConsentGrantResult $grant): bool => $grant->becameActive,
                ))
            ->andReturn([]);

        app()->instance(DispatchWebinarRegistrationMessagesAction::class, $dispatchRegistration);

        $standalone = Mockery::mock(DispatchConsentOptInMessageAction::class);
        $standalone->shouldReceive('handle')->never();
        app()->instance(DispatchConsentOptInMessageAction::class, $standalone);

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')->never();
        app()->instance(AddRegistrantToWebinarProviderAction::class, $provider);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '(555) 555-0123',
                'transactional_email_consent' => true,
                'transactional_sms_consent' => true,
                'marketing_email_consent' => true,
                'marketing_sms_consent' => true,
            ],
            request: $this->registrationRequest(),
            webinarSlug: $webinar,
        );

        $this->assertTrue($result->wasCreated());
        $registration = $result->registration;

        $contact = Contact::query()->where('email', 'jeff@example.com')->firstOrFail();

        $this->assertDatabaseCount('message_consents', 4);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
        ]);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
        ]);
        $this->assertSame(['email', 'sms'], $registration->meta['accepted_channels']['transactional']);
        $this->assertSame(['email', 'sms'], $registration->meta['accepted_channels']['marketing']);
    }

    public function test_existing_registration_dispatches_only_new_consent_acknowledgements_as_standalone_messages(): void
    {
        Queue::fake();

        $webinar = Webinar::factory()->create([
            'external_id' => null,
        ]);
        $contact = Contact::factory()->create([
            'email' => 'jeff@example.com',
        ]);
        $existing = WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
        ]);

        $dispatchRegistration = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $dispatchRegistration->shouldReceive('handle')->never();
        app()->instance(DispatchWebinarRegistrationMessagesAction::class, $dispatchRegistration);

        $standalone = Mockery::mock(DispatchConsentOptInMessageAction::class);
        $standalone->shouldReceive('handle')
            ->once()
            ->withArgs(fn (
                Contact $passedContact,
                MessageConsentGrantResult $grant,
            ): bool => $passedContact->is($contact)
                && $grant->channel === 'email'
                && $grant->purpose === 'transactional'
                && $grant->becameActive)
            ->andReturn([]);
        app()->instance(DispatchConsentOptInMessageAction::class, $standalone);

        $returned = app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Jeff',
                'email' => 'jeff@example.com',
                'transactional_email_consent' => true,
            ],
            request: Request::create('/register', 'POST'),
            webinarSlug: $webinar,
        );

        $this->assertTrue($returned->wasExisting());
        $this->assertTrue($returned->registration->is($existing));
        $this->assertDatabaseCount('webinar_registrations', 1);
        $this->assertDatabaseCount('message_consents', 1);
    }

    private function registrationRequest(): Request
    {
        return Request::create(
            '/register',
            'POST',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'PHPUnit',
            ],
        );
    }

    private function configureWebinarRegistrationChannelAvailability(): void
    {
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => false,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);
    }

    private function enableWebinarRegistrationSms(): void
    {
        Config::set('messaging.channel_availability.sms.surfaces.webinar_registrations', true);
    }
}
