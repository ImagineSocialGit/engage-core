<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
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

    public function test_it_creates_registration_contact_email_consents_and_dispatches(): void
    {
        $webinar = Webinar::factory()->create([
            'external_id' => null,
        ]);

        $dispatchRegistration = Mockery::mock(
            DispatchWebinarRegistrationMessagesAction::class
        );

        $dispatchRegistration
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::type(WebinarRegistration::class));

        app()->instance(
            DispatchWebinarRegistrationMessagesAction::class,
            $dispatchRegistration
        );

        $dispatchMessage = Mockery::mock(
            DispatchMessageAction::class
        );

        $dispatchMessage
            ->shouldReceive('handle')
            ->twice()
            ->andReturn([]);

        app()->instance(
            DispatchMessageAction::class,
            $dispatchMessage
        );

        $provider = Mockery::mock(
            AddRegistrantToWebinarProviderAction::class
        );

        $provider
            ->shouldReceive('handle')
            ->never();

        app()->instance(
            AddRegistrantToWebinarProviderAction::class,
            $provider
        );

        $request = Request::create(
            '/register',
            'POST',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'PHPUnit',
            ]
        );

        $registration = app(
            CreateWebinarRegistrationAction::class
        )->handle(
            validated: [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '(555) 555-0123',

                'transactional_email_consent' => true,
                'marketing_email_consent' => true,
            ],

            request: $request,

            webinarSlug: $webinar->slug,
        );

        $registration->refresh();

        $this->assertInstanceOf(
            WebinarRegistration::class,
            $registration
        );

        $contact = Contact::query()
            ->where('email', 'jeff@example.com')
            ->first();

        $this->assertNotNull($contact);

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
            'webinar_slug' => $webinar->slug,
            'status' => 'pending',
        ]);

        $this->assertSame(
            2,
            MessageConsent::query()
                ->where('contact_id', $contact->id)
                ->count()
        );

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
            'scope' => 'webinar_nurture',
        ]);

        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
        ]);

        $this->assertSame(
            ['email'],
            $registration->meta['accepted_channels']['transactional'] ?? null,
        );

        $this->assertSame(
            ['email'],
            $registration->meta['accepted_channels']['marketing'] ?? null,
        );
    }

    public function test_it_creates_sms_consents_only_when_sms_is_available_and_selected(): void
    {
        $this->enableWebinarRegistrationSms();

        $webinar = Webinar::factory()->create([
            'external_id' => null,
        ]);

        $dispatchRegistration = Mockery::mock(
            DispatchWebinarRegistrationMessagesAction::class
        );

        $dispatchRegistration
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::type(WebinarRegistration::class));

        app()->instance(
            DispatchWebinarRegistrationMessagesAction::class,
            $dispatchRegistration
        );

        $dispatchMessage = Mockery::mock(
            DispatchMessageAction::class
        );

        $dispatchMessage
            ->shouldReceive('handle')
            ->times(4)
            ->andReturn([]);

        app()->instance(
            DispatchMessageAction::class,
            $dispatchMessage
        );

        $provider = Mockery::mock(
            AddRegistrantToWebinarProviderAction::class
        );

        $provider
            ->shouldReceive('handle')
            ->never();

        app()->instance(
            AddRegistrantToWebinarProviderAction::class,
            $provider
        );

        $request = Request::create(
            '/register',
            'POST',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'PHPUnit',
            ]
        );

        $registration = app(
            CreateWebinarRegistrationAction::class
        )->handle(
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

            request: $request,

            webinarSlug: $webinar->slug,
        );

        $registration->refresh();

        $contact = Contact::query()
            ->where('email', 'jeff@example.com')
            ->first();

        $this->assertNotNull($contact);

        $this->assertSame(
            4,
            MessageConsent::query()
                ->where('contact_id', $contact->id)
                ->count()
        );

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
            'scope' => 'webinar_nurture',
        ]);

        $this->assertSame(
            ['email', 'sms'],
            $registration->meta['accepted_channels']['transactional'] ?? null,
        );

        $this->assertSame(
            ['email', 'sms'],
            $registration->meta['accepted_channels']['marketing'] ?? null,
        );
    }

    public function test_it_returns_existing_registration_without_duplicate(): void
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

        $dispatchRegistration = Mockery::mock(
            DispatchWebinarRegistrationMessagesAction::class
        );

        $dispatchRegistration
            ->shouldReceive('handle')
            ->never();

        app()->instance(
            DispatchWebinarRegistrationMessagesAction::class,
            $dispatchRegistration
        );

        $dispatchMessage = Mockery::mock(DispatchMessageAction::class);

        $dispatchMessage
            ->shouldReceive('handle')
            ->once();

        app()->instance(
            DispatchMessageAction::class,
            $dispatchMessage
        );

        $request = Request::create('/register', 'POST');

        $returned = app(
            CreateWebinarRegistrationAction::class
        )->handle(
            validated: [
                'first_name' => 'Jeff',
                'email' => 'jeff@example.com',
                'transactional_email_consent' => true,
            ],

            request: $request,

            webinarSlug: $webinar->slug,
        );

        $this->assertTrue(
            $returned->is($existing)
        );

        $this->assertSame(
            1,
            WebinarRegistration::query()->count()
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
