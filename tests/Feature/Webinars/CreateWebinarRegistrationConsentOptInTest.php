<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
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

    public function test_new_registration_forwards_all_new_consent_transitions_to_the_initial_message_planner(): void
    {
        $webinar = $this->createWebinar();
        $seen = [];

        $dispatchRegistration = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $dispatchRegistration->shouldReceive('handle')
            ->once()
            ->withArgs(function (
                WebinarRegistration $registration,
                ?array $contextKeys,
                array $consentGrants,
            ) use (&$seen): bool {
                $seen = collect($consentGrants)
                    ->map(fn (MessageConsentGrantResult $grant): string => implode(':', [
                        $grant->channel,
                        $grant->purpose,
                        $grant->requestedScope,
                    ]))
                    ->sort()
                    ->values()
                    ->all();

                return $contextKeys === null
                    && count($consentGrants) === 4
                    && collect($consentGrants)->every(
                        fn (MessageConsentGrantResult $grant): bool => $grant->becameActive
                            && $grant->created
                            && $grant->consent->exists,
                    )
                    && $registration->webinar_id !== null;
            })
            ->andReturn([]);

        $this->app->instance(DispatchWebinarRegistrationMessagesAction::class, $dispatchRegistration);

        $standalone = Mockery::mock(DispatchConsentOptInMessageAction::class);
        $standalone->shouldReceive('handle')->never();
        $this->app->instance(DispatchConsentOptInMessageAction::class, $standalone);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: $this->allConsentInput(),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $registration = $result->registration;

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

    public function test_repeated_registration_does_not_request_duplicate_acknowledgements(): void
    {
        $webinar = $this->createWebinar();

        $dispatchRegistration = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $dispatchRegistration->shouldReceive('handle')
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

        $this->app->instance(DispatchWebinarRegistrationMessagesAction::class, $dispatchRegistration);

        $standalone = Mockery::mock(DispatchConsentOptInMessageAction::class);
        $standalone->shouldReceive('handle')->never();
        $this->app->instance(DispatchConsentOptInMessageAction::class, $standalone);

        $action = app(CreateWebinarRegistrationAction::class);

        $action->handle(
            validated: $this->allConsentInput(),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $action->handle(
            validated: $this->allConsentInput(),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

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

    private function createWebinar(): Webinar
    {
        $series = WebinarSeries::factory()->create();

        return Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => null,
        ]);
    }

    private function configureWebinarRegistrationChannelAvailability(): void
    {
        foreach (['email', 'sms'] as $channel) {
            Config::set("messaging.channel_availability.{$channel}", [
                'runtime_supported' => true,
                'provider_enabled' => true,
                'requires_explicit_opt_in' => $channel === 'sms',
                'surfaces' => [
                    'webinar_registrations' => true,
                ],
                'purpose_scopes' => [
                    'transactional:webinar' => true,
                    'marketing:webinar_nurture' => true,
                ],
            ]);
        }
    }
}
