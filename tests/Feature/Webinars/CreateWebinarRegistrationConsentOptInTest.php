<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
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

    public function test_transactional_email_registration_consent_dispatches_opt_in_message(): void
    {
        $webinar = $this->createWebinar();

        $this->mockConsentGrant(
            expectedChannel: MessageChannel::Email,
            expectedPurpose: MessagePurpose::Transactional,
            expectedScope: 'webinar',
            expectedDispatchOptInMessage: true,
        );

        app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => null,
                'transactional_email_consent' => true,
                'transactional_sms_consent' => false,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ],
            request: Request::create('/register', 'POST'),
            webinarSlug: $webinar->slug,
        );

        $registration = WebinarRegistration::query()->first();

        $this->assertNotNull($registration);
        $this->assertSame(['email'], $registration->meta['accepted_channels']['transactional']);
    }

    public function test_transactional_sms_registration_consent_dispatches_opt_in_message(): void
    {
        $webinar = $this->createWebinar();

        $this->mockConsentGrant(
            expectedChannel: MessageChannel::Sms,
            expectedPurpose: MessagePurpose::Transactional,
            expectedScope: 'webinar',
            expectedDispatchOptInMessage: true,
        );

        app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '(555) 555-0123',
                'transactional_email_consent' => false,
                'transactional_sms_consent' => true,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ],
            request: Request::create('/register', 'POST'),
            webinarSlug: $webinar->slug,
        );

        $contact = Contact::query()->where('email', 'jeff@example.com')->first();
        $registration = WebinarRegistration::query()->first();

        $this->assertNotNull($contact);
        $this->assertNotNull($registration);
        $this->assertSame('+15555550123', $contact->phone);
        $this->assertSame(['sms'], $registration->meta['accepted_channels']['transactional']);
    }

    private function mockConsentGrant(
        MessageChannel $expectedChannel,
        MessagePurpose $expectedPurpose,
        string $expectedScope,
        bool $expectedDispatchOptInMessage,
    ): void {
        $mock = Mockery::mock(GrantMessageConsentAction::class);

        $mock->shouldReceive('handle')
            ->once()
            ->withArgs(function (
                Contact $contact,
                array $data,
                array $optInPayload,
                ?Model $context,
                array $resolverContext,
                bool $dispatchOptInMessage,
            ) use ($expectedChannel, $expectedPurpose, $expectedScope, $expectedDispatchOptInMessage): bool {
                return $contact->email === 'jeff@example.com'
                    && $data['channel'] === $expectedChannel->value
                    && $data['purpose'] === $expectedPurpose->value
                    && $data['scope'] === $expectedScope
                    && $data['source'] === 'webinar_registration'
                    && array_key_exists('webinar_registration_id', $data['meta'])
                    && array_key_exists('webinar_id', $optInPayload)
                    && $context instanceof WebinarRegistration
                    && $resolverContext['webinar_slug'] === $context->webinar_slug
                    && $dispatchOptInMessage === $expectedDispatchOptInMessage;
            })
            ->andReturn(new MessageConsent());

        $this->app->instance(GrantMessageConsentAction::class, $mock);
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
