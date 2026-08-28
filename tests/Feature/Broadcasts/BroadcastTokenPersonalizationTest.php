<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageTemplateAuthoringFieldPresenter;
use App\Modules\Messaging\Services\MessageTokenFallbackResolver;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BroadcastTokenPersonalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.modules.broadcasts.enabled' => true,
            'modules.modules.messaging.enabled' => true,
            'messaging.channel_availability.email.runtime_supported' => true,
            'messaging.channel_availability.email.surfaces.broadcasts' => true,
            'messaging.email.from.marketing.address' => 'marketing@example.test',
            'messaging.email.from.marketing.name' => 'Marketing',
        ]);
    }

    public function test_broadcast_send_context_exposes_contact_fields_to_authoring(): void
    {
        $registry = app(TokenContractRegistry::class);
        $context = $registry->context(Broadcast::DEFAULT_DISPATCH_KEY);

        $this->assertSame('broadcasts', $context->owner);
        $this->assertEqualsCanonicalizing(['email', 'sms'], $context->channels);
        $this->assertContains('first_name', $registry->authorableTokens(Broadcast::DEFAULT_DISPATCH_KEY));
        $this->assertContains('contact.source', $registry->authorableTokens(Broadcast::DEFAULT_DISPATCH_KEY));
        $this->assertNotContains('birthday', $registry->authorableTokens(Broadcast::DEFAULT_DISPATCH_KEY));

        $fields = collect(app(MessageTemplateAuthoringFieldPresenter::class)
            ->groupsForContext(Broadcast::DEFAULT_DISPATCH_KEY))
            ->flatMap(fn (array $group): array => $group['fields'])
            ->values();

        $firstName = $fields->firstWhere('insert_token', 'first_name');

        $this->assertIsArray($firstName);
        $this->assertSame('{first_name}', $firstName['syntax']);

        $this
            ->actingAs(User::factory()->create())
            ->get(route('crm.broadcasts.index'))
            ->assertOk()
            ->assertSee('data-broadcast-message-personalization', false)
            ->assertSee('data-message-field-token="contact.first_name"', false)
            ->assertSee('name="token_fallbacks_present"', false);
    }

    public function test_regular_broadcast_authoring_persists_explicit_missing_field_behavior_and_rejects_unknown_fields(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'Birthday Broadcast',
                'channel' => 'email',
                'subject' => 'Birthday note',
                'body' => 'Hey {first_name}, Happy birthday!',
                'recipient_filter_type' => 'all',
                'token_fallbacks_present' => '1',
                'token_fallbacks' => [[
                    'token' => 'first_name',
                    'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT,
                    'segment' => 'Hey {first_name}, ',
                    'fallback' => '',
                ]],
            ]);

        $response->assertSessionHasNoErrors();

        $broadcast = Broadcast::query()->sole();

        $this->assertEquals([[
            'token' => 'first_name',
            'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT,
            'fallback' => '',
            'segment' => 'Hey {first_name},',
        ]], $broadcast->payload['token_fallbacks']);

        $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'Invalid Broadcast',
                'channel' => 'email',
                'subject' => 'Unknown field',
                'body' => 'Hello {does_not_exist}',
                'recipient_filter_type' => 'all',
                'token_fallbacks_present' => '1',
            ])
            ->assertSessionHasErrors('body');

        $this->assertSame(1, Broadcast::query()->count());
    }

    public function test_broadcast_copy_is_personalized_independently_for_each_recipient_and_uses_23c1_fallback_behavior(): void
    {
        $namedContact = Contact::factory()->create([
            'first_name' => 'Ada',
            'email' => 'ada@example.test',
        ]);
        $missingNameContact = Contact::factory()->create([
            'first_name' => null,
            'email' => 'friend@example.test',
        ]);

        foreach ([$namedContact, $missingNameContact] as $contact) {
            MessageConsent::query()->create([
                'contact_id' => $contact->getKey(),
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'broadcast',
                'consented_at' => now(),
                'source' => 'test',
            ]);
        }

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => EmailPayload::class,
            'send_at' => now()->addHour(),
            'recipient_filter' => [
                'type' => 'contact_ids',
                'contact_ids' => [
                    $namedContact->getKey(),
                    $missingNameContact->getKey(),
                ],
            ],
            'payload' => [
                'subject' => 'Birthday note',
                'body' => 'Hey {first_name}, Happy birthday!',
                'token_fallbacks' => [[
                    'token' => 'first_name',
                    'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT,
                    'segment' => 'Hey {first_name},',
                    'fallback' => '',
                ]],
            ],
        ]);

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(2, ScheduledMessage::query()->count());

        $resolver = app(ScheduledMessagePayloadResolver::class);
        $namedMessage = ScheduledMessage::query()
            ->where('recipient_id', $namedContact->getKey())
            ->sole();
        $missingMessage = ScheduledMessage::query()
            ->where('recipient_id', $missingNameContact->getKey())
            ->sole();

        $this->assertSame(
            'Hey Ada, Happy birthday!',
            $resolver->resolve($namedMessage)->text(),
        );
        $this->assertSame(
            'Happy birthday!',
            $resolver->resolve($missingMessage)->text(),
        );
    }

    public function test_invalid_legacy_broadcast_draft_is_blocked_before_recipient_snapshot(): void
    {
        Contact::factory()->create([
            'email' => 'recipient@example.test',
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => EmailPayload::class,
            'recipient_filter' => ['type' => 'all'],
            'payload' => [
                'subject' => 'Invalid legacy draft',
                'body' => 'Hello {does_not_exist}',
            ],
        ]);

        try {
            app(ScheduleBroadcastAction::class)->handle($broadcast);
            $this->fail('Invalid tokenized Broadcast should not schedule.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('unknown token', strtolower($exception->getMessage()));
        }

        $this->assertSame(Broadcast::STATUS_DRAFT, $broadcast->fresh()->status);
        $this->assertSame(0, BroadcastRecipient::query()->count());
        $this->assertSame(0, ScheduledMessage::query()->count());
    }
}