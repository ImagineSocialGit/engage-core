<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\ModuleIntegrations\Scheduling\Messaging\MessagingAppointmentCommunications;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentMessageChainExecutionContextProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTokenContextProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTokenSourceProvider;
use App\Support\TokenContracts\TokenContractRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppointmentCommunicationsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-28 17:00:00 UTC');
        $this->registerIntegrationTokens();

        config()->set('messaging.channel_availability.email.runtime_supported', true);
        config()->set('messaging.channel_availability.email.provider_enabled', true);
        config()->set('messaging.channel_availability.email.surfaces.scheduling_appointments', true);
        config()->set('messaging.channel_availability.email.purpose_scopes', ['*' => true]);
        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', false);
        config()->set('messaging.channel_availability.sms.surfaces.scheduling_appointments', true);
        config()->set('messaging.channel_availability.sms.purpose_scopes', ['*' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_manual_appointment_enrolls_and_reschedule_replaces_then_cancellation_stops_future_messages(): void
    {
        $communications = app(MessagingAppointmentCommunications::class);
        $communications->generateDefaultSchedule();

        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);
        app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'scheduling_appointments',
            'source' => 'test',
        ]);

        $original = Appointment::factory()->create([
            'contact_id' => $contact->getKey(),
            'starts_at' => CarbonImmutable::now('UTC')->addDays(7),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(7)->addHour(),
        ]);

        $communications->appointmentCreated($original);

        $originalEnrollment = MessageChainEnrollment::query()
            ->where('context_type', $original->getMorphClass())
            ->where('context_id', $original->getKey())
            ->sole();

        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $originalEnrollment->status);
        $this->assertSame('scheduling_appointments', $originalEnrollment->surface);

        $replacement = Appointment::factory()->create([
            'contact_id' => $contact->getKey(),
            'rescheduled_from_id' => $original->getKey(),
            'starts_at' => CarbonImmutable::now('UTC')->addDays(9),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(9)->addHour(),
        ]);

        $communications->appointmentRescheduled(
            original: $original,
            replacement: $replacement,
        );

        $this->assertSame(
            MessageChainEnrollment::STATUS_CANCELLED,
            $originalEnrollment->refresh()->status,
        );
        $this->assertSame(
            'appointment_rescheduled',
            $originalEnrollment->exit_reason_code,
        );

        $replacementEnrollment = MessageChainEnrollment::query()
            ->where('context_type', $replacement->getMorphClass())
            ->where('context_id', $replacement->getKey())
            ->sole();

        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $replacementEnrollment->status);

        $communications->appointmentCancelled($replacement);

        $this->assertSame(
            MessageChainEnrollment::STATUS_CANCELLED,
            $replacementEnrollment->refresh()->status,
        );
        $this->assertSame(
            'appointment_cancelled',
            $replacementEnrollment->exit_reason_code,
        );
    }

    public function test_completion_cancels_the_booking_sequence_and_starts_due_follow_up_from_the_first_after_message(): void
    {
        $communications = app(MessagingAppointmentCommunications::class);
        $plan = $communications->generateDefaultSchedule();
        $communications->saveSchedule([
            ...$plan['steps'],
            [
                'key' => 'follow_up_2_hours',
                'name' => '2-hour follow-up',
                'timing' => 'after',
                'offset_value' => 2,
                'offset_unit' => 'hours',
                'channels' => ['email'],
                'subject' => 'Appointment follow-up',
                'message' => 'Thank you for meeting with us, {first_name}.',
            ],
        ]);

        $contact = Contact::factory()->create([
            'email' => 'follow-up@example.test',
        ]);
        app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'scheduling_appointments',
            'source' => 'test',
        ]);

        $appointment = Appointment::factory()->create([
            'contact_id' => $contact->getKey(),
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => CarbonImmutable::now('UTC')->subHours(3),
            'ends_at' => CarbonImmutable::now('UTC')->subHours(2),
        ]);

        $communications->appointmentCreated($appointment);

        $bookingEnrollment = MessageChainEnrollment::query()
            ->where('context_type', $appointment->getMorphClass())
            ->where('context_id', $appointment->getKey())
            ->where('dedupe_key', 'scheduling:appointment:'.$appointment->getKey().':communications')
            ->sole();

        $appointment->forceFill([
            'status' => Appointment::STATUS_COMPLETED,
            'completed_at' => CarbonImmutable::now('UTC'),
        ])->save();

        $communications->appointmentCompleted($appointment->fresh());

        $this->assertSame(
            MessageChainEnrollment::STATUS_CANCELLED,
            $bookingEnrollment->refresh()->status,
        );
        $this->assertSame(
            'appointment_completed',
            $bookingEnrollment->exit_reason_code,
        );

        $followUp = MessageChainEnrollment::query()
            ->with('currentMessageChainStep')
            ->where('context_type', $appointment->getMorphClass())
            ->where('context_id', $appointment->getKey())
            ->where('dedupe_key', 'scheduling:appointment:'.$appointment->getKey().':communications:follow_up')
            ->sole();

        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $followUp->status);
        $this->assertSame('follow_up_2_hours', $followUp->currentMessageChainStep?->key);
        $this->assertTrue(
            $followUp->next_action_at?->equalTo(CarbonImmutable::now('UTC')) ?? false,
        );
    }

    public function test_public_booking_waits_for_public_completion_before_starting_communications(): void
    {
        $communications = app(MessagingAppointmentCommunications::class);
        $communications->generateDefaultSchedule();

        $contact = Contact::factory()->create([
            'email' => 'public@example.test',
        ]);
        $appointment = Appointment::factory()->create([
            'contact_id' => $contact->getKey(),
            'source' => 'public_booking',
        ]);

        $communications->appointmentCreated($appointment);

        $this->assertDatabaseMissing('message_chain_enrollments', [
            'context_type' => $appointment->getMorphClass(),
            'context_id' => $appointment->getKey(),
            'surface' => 'scheduling_appointments',
        ]);
    }

    private function registerIntegrationTokens(): void
    {
        $this->app->tag(
            SchedulingAppointmentTokenSourceProvider::class,
            'token.source_providers',
        );
        $this->app->tag(
            SchedulingAppointmentTokenContextProvider::class,
            'token.context_providers',
        );
        $this->app->tag(
            SchedulingAppointmentMessageChainExecutionContextProvider::class,
            'messaging.message_chain_execution_context_providers',
        );

        $this->app->forgetInstance(TokenContractRegistry::class);
    }
}