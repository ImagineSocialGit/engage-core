<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\CreateAppointmentAction;
use App\Modules\Scheduling\Contracts\BookingSubjectProvider;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServicePrerequisite;
use App\Modules\Scheduling\Services\BookableServiceBookingRuleGuard;
use App\Modules\Scheduling\Services\BookableServicePrerequisiteEvaluator;
use App\Modules\Scheduling\Services\BookingSubjectProviderRegistry;
use App\Modules\Scheduling\Validation\SchedulingSetupValidationContributor;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class SchedulingBookingSubjectPrerequisiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->tag(
            TestUserBookingSubjectProvider::class,
            BookingSubjectProviderRegistry::TAG,
        );
    }

    public function test_generic_is_the_backward_compatible_default_and_subject_providers_are_extensible(): void
    {
        $service = BookableService::factory()->create();
        $contact = Contact::factory()->create();
        $user = User::factory()->create();
        $registry = app(BookingSubjectProviderRegistry::class);

        $this->assertSame(BookableService::BOOKING_SUBJECT_GENERIC, $service->bookingSubjectKey());
        $this->assertTrue($registry->accepts(BookableService::BOOKING_SUBJECT_GENERIC, $contact));
        $this->assertTrue($registry->accepts(BookableService::BOOKING_SUBJECT_CONTACT, $contact));
        $this->assertTrue($registry->accepts(TestUserBookingSubjectProvider::KEY, $user));

        $definitions = collect($registry->definitions())->keyBy('key');

        $this->assertSame('Any attendee', $definitions->get(BookableService::BOOKING_SUBJECT_GENERIC)['label']);
        $this->assertSame('Person', $definitions->get(BookableService::BOOKING_SUBJECT_CONTACT)['label']);
        $this->assertSame('Test user', $definitions->get(TestUserBookingSubjectProvider::KEY)['label']);
    }

    public function test_prerequisites_count_only_completed_appointments_for_the_exact_booking_subject(): void
    {
        $evaluation = BookableService::factory()->create(['name' => 'Evaluation']);
        $training = BookableService::factory()->create(['name' => 'Training']);
        $first = Contact::factory()->create();
        $second = Contact::factory()->create();
        $at = CarbonImmutable::parse('2026-09-30 12:00:00 UTC');

        $prerequisite = BookableServicePrerequisite::query()->create([
            'bookable_service_id' => $training->getKey(),
            'prerequisite_bookable_service_id' => $evaluation->getKey(),
            'required_completions' => 2,
        ]);

        Appointment::factory()
            ->forPrimaryAttendee($first)
            ->completed()
            ->create([
                'bookable_service_id' => $evaluation->getKey(),
                'completed_at' => $at->subDays(3),
            ]);

        Appointment::factory()
            ->forPrimaryAttendee($first)
            ->completed()
            ->create([
                'bookable_service_id' => $evaluation->getKey(),
                'completed_at' => $at->subDay(),
            ]);

        Appointment::factory()
            ->forPrimaryAttendee($first)
            ->create([
                'bookable_service_id' => $evaluation->getKey(),
                'status' => Appointment::STATUS_CONFIRMED,
                'completed_at' => null,
            ]);

        Appointment::factory()
            ->forPrimaryAttendee($second)
            ->completed()
            ->create([
                'bookable_service_id' => $evaluation->getKey(),
                'completed_at' => $at->subDay(),
            ]);

        $evaluator = app(BookableServicePrerequisiteEvaluator::class);
        $firstResult = $evaluator->evaluate($training, $first, $at);
        $secondResult = $evaluator->evaluate($training, $second, $at);

        $this->assertTrue($firstResult->satisfied());
        $this->assertSame(2, $firstResult->requirements[0]->completedCount);
        $this->assertSame(2, $firstResult->requirements[0]->requiredCompletions());
        $this->assertTrue($firstResult->requirements[0]->prerequisite->is($prerequisite));

        $this->assertFalse($secondResult->satisfied());
        $this->assertSame(1, $secondResult->requirements[0]->completedCount);
        $this->assertCount(1, $secondResult->missing());

        $this->assertTrue($training->fresh()->prerequisites->contains($prerequisite));
        $this->assertTrue($evaluation->fresh()->prerequisiteForServices->contains($prerequisite));
    }

    public function test_prerequisite_validity_window_ignores_expired_completions(): void
    {
        $evaluation = BookableService::factory()->create();
        $training = BookableService::factory()->create();
        $contact = Contact::factory()->create();
        $at = CarbonImmutable::parse('2026-09-30 12:00:00 UTC');

        BookableServicePrerequisite::query()->create([
            'bookable_service_id' => $training->getKey(),
            'prerequisite_bookable_service_id' => $evaluation->getKey(),
            'required_completions' => 1,
            'valid_for_days' => 30,
        ]);

        Appointment::factory()
            ->forPrimaryAttendee($contact)
            ->completed()
            ->create([
                'bookable_service_id' => $evaluation->getKey(),
                'completed_at' => $at->subDays(31),
            ]);

        $expired = app(BookableServicePrerequisiteEvaluator::class)
            ->evaluate($training, $contact, $at);

        $this->assertFalse($expired->satisfied());
        $this->assertSame(0, $expired->requirements[0]->completedCount);

        Appointment::factory()
            ->forPrimaryAttendee($contact)
            ->completed()
            ->create([
                'bookable_service_id' => $evaluation->getKey(),
                'completed_at' => $at->subDays(30),
            ]);

        $current = app(BookableServicePrerequisiteEvaluator::class)
            ->evaluate($training, $contact, $at);

        $this->assertTrue($current->satisfied());
        $this->assertSame(1, $current->requirements[0]->completedCount);
    }

    public function test_non_contact_booking_subjects_use_the_same_generic_prerequisite_engine(): void
    {
        $evaluation = BookableService::factory()->create([
            'booking_subject_key' => TestUserBookingSubjectProvider::KEY,
        ]);
        $training = BookableService::factory()->create([
            'booking_subject_key' => TestUserBookingSubjectProvider::KEY,
        ]);
        $subject = User::factory()->create();
        $at = CarbonImmutable::parse('2026-09-30 12:00:00 UTC');

        BookableServicePrerequisite::query()->create([
            'bookable_service_id' => $training->getKey(),
            'prerequisite_bookable_service_id' => $evaluation->getKey(),
        ]);

        Appointment::factory()
            ->forPrimaryAttendee($subject)
            ->completed()
            ->create([
                'bookable_service_id' => $evaluation->getKey(),
                'completed_at' => $at->subDay(),
            ]);

        $result = app(BookableServicePrerequisiteEvaluator::class)
            ->evaluate($training, $subject, $at);

        $this->assertTrue($result->satisfied());
        $this->assertSame(1, $result->requirements[0]->completedCount);
    }

    public function test_booking_rule_guard_preserves_snapshot_only_person_bookings_without_prerequisites(): void
    {
        $service = BookableService::factory()->create();

        $subject = app(BookableServiceBookingRuleGuard::class)->assertSatisfied(
            service: $service,
            booking: new AppointmentBookingData(
                name: 'Snapshot Guest',
                email: 'snapshot@example.test',
                source: 'crm',
            ),
        );

        $this->assertNull($subject);
    }

    public function test_booking_rule_guard_requires_a_persisted_subject_for_non_snapshot_subject_types(): void
    {
        $service = BookableService::factory()->create([
            'booking_subject_key' => TestUserBookingSubjectProvider::KEY,
        ]);

        $this->expectException(DomainException::class);

        app(BookableServiceBookingRuleGuard::class)->assertSatisfied(
            service: $service,
            booking: new AppointmentBookingData(
                name: 'Unresolved subject',
                source: 'crm',
            ),
        );
    }

    public function test_direct_creation_enforces_the_service_booking_subject_before_availability_commit(): void
    {
        $service = BookableService::factory()->create([
            'booking_subject_key' => TestUserBookingSubjectProvider::KEY,
        ]);
        $contact = Contact::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'The selected booking subject is not valid for this Appointment Type.',
        );

        app(CreateAppointmentAction::class)->handle(new AppointmentCreationData(
            service: $service,
            startsAt: CarbonImmutable::now('UTC')->addDay()->startOfHour(),
            booking: new AppointmentBookingData(contact: $contact, source: 'crm'),
            idempotencyKey: 'subject-policy-direct-create',
        ));
    }

    public function test_direct_creation_enforces_active_prerequisites_before_availability_commit(): void
    {
        $evaluation = BookableService::factory()->create();
        $training = BookableService::factory()->create();
        $contact = Contact::factory()->create();

        BookableServicePrerequisite::query()->create([
            'bookable_service_id' => $training->getKey(),
            'prerequisite_bookable_service_id' => $evaluation->getKey(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'The selected booking subject has not completed all required prerequisite appointments.',
        );

        app(CreateAppointmentAction::class)->handle(new AppointmentCreationData(
            service: $training,
            startsAt: CarbonImmutable::now('UTC')->addDay()->startOfHour(),
            booking: new AppointmentBookingData(contact: $contact, source: 'crm'),
            idempotencyKey: 'prerequisite-policy-direct-create',
        ));
    }

    public function test_evaluation_rejects_a_subject_that_does_not_match_the_service_provider(): void
    {
        $service = BookableService::factory()->create([
            'booking_subject_key' => TestUserBookingSubjectProvider::KEY,
        ]);
        $contact = Contact::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(BookableServicePrerequisiteEvaluator::class)
            ->evaluate($service, $contact);
    }

    public function test_evaluation_refuses_cross_subject_prerequisites_even_when_both_subject_providers_exist(): void
    {
        $contactService = BookableService::factory()->create([
            'booking_subject_key' => BookableService::BOOKING_SUBJECT_CONTACT,
        ]);
        $userService = BookableService::factory()->create([
            'booking_subject_key' => TestUserBookingSubjectProvider::KEY,
        ]);
        $contact = Contact::factory()->create();

        BookableServicePrerequisite::query()->create([
            'bookable_service_id' => $contactService->getKey(),
            'prerequisite_bookable_service_id' => $userService->getKey(),
        ]);

        $this->expectException(LogicException::class);

        app(BookableServicePrerequisiteEvaluator::class)
            ->evaluate($contactService, $contact);
    }

    public function test_setup_validation_reports_missing_subject_providers_and_cross_subject_prerequisites(): void
    {
        config()->set('scheduling.public.configured', false);
        config()->set('scheduling.public.enabled', false);

        BookableService::factory()->create([
            'booking_subject_key' => 'unavailable_subject',
        ]);

        $contactService = BookableService::factory()->create([
            'booking_subject_key' => BookableService::BOOKING_SUBJECT_CONTACT,
        ]);
        $userService = BookableService::factory()->create([
            'booking_subject_key' => TestUserBookingSubjectProvider::KEY,
        ]);

        BookableServicePrerequisite::query()->create([
            'bookable_service_id' => $contactService->getKey(),
            'prerequisite_bookable_service_id' => $userService->getKey(),
        ]);

        $codes = collect(iterator_to_array(
            app(SchedulingSetupValidationContributor::class)->findings(),
            false,
        ))->pluck('code')->all();

        $this->assertContains('scheduling.booking_subject_provider_missing', $codes);
        $this->assertContains('scheduling.prerequisite_subject_mismatch', $codes);
    }
}

final class TestUserBookingSubjectProvider implements BookingSubjectProvider
{
    public const KEY = 'test_user';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Test user';
    }

    public function accepts(Model $subject): bool
    {
        return $subject instanceof User;
    }

    public function allowsSnapshotOnly(): bool
    {
        return false;
    }
}