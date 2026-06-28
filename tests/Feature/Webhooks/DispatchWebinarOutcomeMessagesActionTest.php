<?php

namespace Tests\Feature\Webhooks;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Actions\PostEvent\DispatchWebinarOutcomeMessagesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarOutcomeMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_no_longer_dispatches_attended_messages_directly(): void
    {
        Queue::fake();
        Event::fake([AutomationEventRecorded::class]);

        $webinar = Webinar::factory()->create([
            'ends_at' => now(),
        ]);

        $contact = Contact::factory()->create();

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create([
                'status' => 'registered',
                'registered_at' => now(),
                'attended_at' => now(),
            ]);

        app(DispatchWebinarOutcomeMessagesAction::class)->handle(
            registration: $registration,
            event: 'webinar.ended',
        );

        $this->assertSame(0, ScheduledMessage::query()->count());

        Queue::assertNotPushed(SendScheduledMessageJob::class);
        Event::assertNotDispatched(AutomationEventRecorded::class);
    }

    public function test_it_no_longer_dispatches_missed_messages_directly(): void
    {
        Queue::fake();
        Event::fake([AutomationEventRecorded::class]);

        $webinar = Webinar::factory()->create([
            'ends_at' => now(),
        ]);

        $contact = Contact::factory()->create();

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create([
                'status' => 'registered',
                'registered_at' => now(),
                'attended_at' => null,
                'meta' => [
                    'attendance' => [
                        'status' => 'missed',
                    ],
                ],
            ]);

        app(DispatchWebinarOutcomeMessagesAction::class)->handle(
            registration: $registration,
            event: 'webinar.ended',
        );

        $this->assertSame(0, ScheduledMessage::query()->count());

        Queue::assertNotPushed(SendScheduledMessageJob::class);
        Event::assertNotDispatched(AutomationEventRecorded::class);
    }
}