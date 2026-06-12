<?php

namespace Tests\Feature\Messaging;

use App\Actions\Messaging\DispatchMessageAction;
use App\Actions\Messaging\GrantMessageConsentAction;
use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Models\MessageConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GrantMessageConsentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_message_consent(): void
    {
        $contact = Contact::factory()->create();

        $this->mockDispatchMessageActionOnce();

        $consent = app(GrantMessageConsentAction::class)->handle(
            contact: $contact,
            data: [
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'source' => 'test',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'consented_at' => now(),
            ],
        );

        $this->assertInstanceOf(MessageConsent::class, $consent);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'source' => 'test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
    }

    public function test_it_updates_existing_consent_without_creating_duplicate(): void
    {
        $contact = Contact::factory()->create();

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subDay(),
            'source' => 'old',
        ]);

        $this->mockDispatchMessageActionNever();

        app(GrantMessageConsentAction::class)->handle(
            contact: $contact,
            data: [
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'source' => 'new',
                'consented_at' => now(),
            ],
        );

        $this->assertSame(
            1,
            MessageConsent::query()
                ->where('contact_id', $contact->id)
                ->where('channel', 'email')
                ->where('purpose', 'marketing')
                ->where('scope', 'webinar')
                ->count()
        );

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'source' => 'new',
        ]);
    }

    public function test_it_dispatches_consent_granted_message_only_when_consent_becomes_active(): void
    {
        $contact = Contact::factory()->create();

        $dispatchMessageAction = Mockery::mock(DispatchMessageAction::class);

        $dispatchMessageAction
            ->shouldReceive('handle')
            ->once()
            ->withArgs(function (
                Contact $passedContact,
                string $channel,
                string $purpose,
                string $scope,
                string|array $dispatchKeys,
                array $payload,
            ) use ($contact): bool {
                return $passedContact->is($contact)
                    && $channel === 'email'
                    && $purpose === 'marketing'
                    && $scope === 'webinar'
                    && $dispatchKeys === 'consent_granted'
                    && $payload === [
                        'tokens' => [
                            'first_name' => 'Jeff',
                        ],
                    ];
            })
            ->andReturn([]);

        app()->instance(DispatchMessageAction::class, $dispatchMessageAction);

        app(GrantMessageConsentAction::class)->handle(
            contact: $contact,
            data: [
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'source' => 'test',
                'consented_at' => now(),
            ],
            optInPayload: [
                'tokens' => [
                    'first_name' => 'Jeff',
                ],
            ],
        );
    }

    public function test_it_does_not_dispatch_when_existing_consent_is_already_active(): void
    {
        $contact = Contact::factory()->create();

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subDay(),
            'source' => 'existing',
        ]);

        $this->mockDispatchMessageActionNever();

        app(GrantMessageConsentAction::class)->handle(
            contact: $contact,
            data: [
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'source' => 'test',
                'consented_at' => now(),
            ],
        );
    }

    public function test_it_dispatches_when_previous_consent_was_revoked(): void
    {
        $contact = Contact::factory()->create();

        $consent = MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subDays(2),
            'source' => 'existing',
        ]);

        ConsentRevocation::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'revoked_at' => $consent->consented_at->copy()->addDay(),
            'source' => 'test',
        ]);

        $this->mockDispatchMessageActionOnce();

        app(GrantMessageConsentAction::class)->handle(
            contact: $contact,
            data: [
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'source' => 'test',
                'consented_at' => now(),
            ],
        );
    }

    private function mockDispatchMessageActionOnce(): void
    {
        $dispatchMessageAction = Mockery::mock(DispatchMessageAction::class);

        $dispatchMessageAction
            ->shouldReceive('handle')
            ->once()
            ->andReturn([]);

        app()->instance(DispatchMessageAction::class, $dispatchMessageAction);
    }

    private function mockDispatchMessageActionNever(): void
    {
        $dispatchMessageAction = Mockery::mock(DispatchMessageAction::class);

        $dispatchMessageAction
            ->shouldReceive('handle')
            ->never();

        app()->instance(DispatchMessageAction::class, $dispatchMessageAction);
    }
}