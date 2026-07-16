<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class CreateWebinarRegistrationConsentOptInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWebinarRegistrationChannelAvailability();
    }

    public function test_all_selected_registration_consents_are_recorded_then_explicitly_forwarded_for_acknowledgement(): void
    {
        $webinar = $this->createWebinar();
        $seen = [];

        $dispatch = Mockery::mock(DispatchConsentOptInMessageAction::class);
        $dispatch->shouldReceive('handle')
            ->times(4)
            ->withArgs(function (
                Contact $contact,
                MessageConsentGrantResult $grant,
                array $payload,
                ?Model $context,
                array $resolverContext,
            ) use (&$seen): bool {
                $seen[] = implode(':', [
                    $grant->channel,
                    $grant->purpose,
                    $grant->requestedScope,
                ]);

                return $contact->email === 'jeff@example.com'
                    && $grant->becameActive
                    && $grant->created
                    && $grant->consent->exists
                    && array_key_exists('webinar_id', $payload)
                    && $context instanceof WebinarRegistration
                    && ($resolverContext['webinar_slug'] ?? null) === $context->webinar_slug;
            })
            ->andReturn([]);

        $this->app->instance(DispatchConsentOptInMessageAction::class, $dispatch);
        $this->mockRegistrationLifecycleDispatch();

        $registration = app(CreateWebinarRegistrationAction::class)->handle(
            validated: $this->allConsentInput(),
            request: Request::create('/register', 'POST'),
            webinarSlug: $webinar->slug,
        );

        sort($seen);

        $this->assertSame([
            'email:marketing:webinar_nurture',
            'email:transactional:webinar',
            'sms:marketing:webinar_nurture',
            'sms:transactional:webinar',
        ], $seen);
        $this->assertSame(['email', 'sms'], $registration->meta['accepted_channels']['transactional']);
        $this->assertSame(['email', 'sms'], $registration->meta['accepted_channels']['marketing']);
        $this->assertDatabaseCount('message_consents', 4);
    }

    public function test_repeated_registration_does_not_request_duplicate_acknowledgements_for_active_consents(): void
    {
        $webinar = $this->createWebinar();

        $dispatch = Mockery::mock(DispatchConsentOptInMessageAction::class);
        $dispatch->shouldReceive('handle')
            ->times(4)
            ->withArgs(fn (
                Contact $contact,
                MessageConsentGrantResult $grant,
            ): bool => $contact->email === 'jeff@example.com'
                && $grant->becameActive
                && $grant->created)
            ->andReturn([]);

        $this->app->instance(DispatchConsentOptInMessageAction::class, $dispatch);
        $this->mockRegistrationLifecycleDispatch();

        $action = app(CreateWebinarRegistrationAction::class);

        $action->handle(
            validated: $this->allConsentInput(),
            request: Request::create('/register', 'POST'),
            webinarSlug: $webinar->slug,
        );

        $action->handle(
            validated: $this->allConsentInput(),
            request: Request::create('/register', 'POST'),
            webinarSlug: $webinar->slug,
        );

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('webinar_registrations', 1);
        $this->assertDatabaseCount('message_consents', 4);
    }

    /** @return array<string, mixed> */
    private function allConsentInput(): array
    {
        return [
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '(555) 555-0123',
            'transactional_email_consent' => true,
            'transactional_sms_consent' => true,
            'marketing_email_consent' => true,
            'marketing_sms_consent' => true,
        ];
    }

    private function mockRegistrationLifecycleDispatch(): void
    {
        $mock = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $mock->shouldReceive('handle')
            ->once()
            ->andReturn([]);

        $this->app->instance(DispatchWebinarRegistrationMessagesAction::class, $mock);
    }

    private function createWebinar(): Webinar
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
            'title' => 'Homebuyer Basics',
        ]);

        return Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'slug' => 'homebuyer-basics-session',
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);
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
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
