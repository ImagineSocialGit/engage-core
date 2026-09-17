<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OutboundContextLaunchersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_contact_group_is_session_scoped_and_can_be_refined(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        $user = User::factory()->create();
        $first = Contact::factory()->create(['first_name' => 'Ariadne']);
        $second = Contact::factory()->create(['first_name' => 'Boris']);
        $included = ScheduledMessage::factory()->forRecipient($first)->create(['send_at' => now()->addHour()]);
        $excluded = ScheduledMessage::factory()->forRecipient($second)->create(['send_at' => now()->addHour()]);

        $this->actingAs($user)->post(route('crm.messaging.outbound.contact-group'), [
            'contact_result' => ['search' => 'Ariadne', 'criteria' => []],
        ])->assertRedirect();

        $groups = session('outbound.contact_groups');
        $this->assertCount(1, $groups);
        $token = array_key_first($groups);

        $this->get(route('crm.messaging.outbound.index', [
            'group' => $token, 'period' => 'upcoming', 'embedded' => 1, 'channel' => $included->channel,
        ]))->assertOk()->assertViewHas('messages', function ($page) use ($included, $excluded): bool {
            $ids = $page->getCollection()->modelKeys();

            return in_array($included->getKey(), $ids, true)
                && ! in_array($excluded->getKey(), $ids, true);
        });

        $this->get(route('crm.messaging.outbound.index', ['group' => 'unknown']))->assertNotFound();
    }
}