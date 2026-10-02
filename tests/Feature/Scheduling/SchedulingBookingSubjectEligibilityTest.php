<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Contracts\BookingSubjectEligibilityProvider;
use App\Modules\Scheduling\Contracts\BookingSubjectProvider;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\BookingSubjectEligibilityContext;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Services\BookableServiceBookingRuleGuard;
use App\Modules\Scheduling\Services\BookingSubjectEligibilityProviderRegistry;
use App\Modules\Scheduling\Services\BookingSubjectProviderRegistry;
use App\Modules\Scheduling\Validation\SchedulingSetupValidationContributor;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class SchedulingBookingSubjectEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(TestEligibilityUserSubjectProvider::class);
        $this->app->singleton(TestEligibilityUserPolicyProvider::class);

        $this->app->tag(
            TestEligibilityUserSubjectProvider::class,
            BookingSubjectProviderRegistry::TAG,
        );
        $this->app->tag(
            TestEligibilityUserPolicyProvider::class,
            BookingSubjectEligibilityProviderRegistry::TAG,
        );
    }

    public function test_booking_subject_policy_is_a_provider_neutral_array_contract(): void
    {
        $service = BookableService::factory()->create([
            'booking_subject_key' => TestEligibilityUserSubjectProvider::KEY,
            'booking_subject_policy' => [
                'allow' => true,
            ],
        ]);

        $this->assertSame(
            ['allow' => true],
            $service->fresh()->bookingSubjectPolicy(),
        );

        app(BookingSubjectEligibilityProviderRegistry::class)
            ->validatePolicy($service->fresh());
    }

    public function test_services_without_subject_policy_preserve_existing_booking_behavior(): void
    {
        $service = BookableService::factory()->create();
        $contact = Contact::factory()->create();

        $subject = app(BookableServiceBookingRuleGuard::class)->assertSatisfied(
            service: $service,
            booking: new AppointmentBookingData(contact: $contact, source: 'crm'),
        );

        $this->assertTrue($subject?->is($contact));
    }

    public function test_booking_rule_guard_passes_the_exact_appointment_window_to_subject_eligibility(): void
    {
        $service = BookableService::factory()->create([
            'booking_subject_key' => TestEligibilityUserSubjectProvider::KEY,
            'booking_subject_policy' => [
                'allow' => true,
            ],
        ]);
        $subject = User::factory()->create();
        $startsAt = CarbonImmutable::parse('2026-10-15 14:00:00 UTC');
        $endsAt = $startsAt->addDays(5);
        $evaluatedAt = CarbonImmutable::parse('2026-10-01 20:00:00 UTC');

        $resolved = app(BookableServiceBookingRuleGuard::class)->assertSatisfied(
            service: $service,
            booking: new AppointmentBookingData(
                primaryAttendee: $subject,
                source: 'crm',
            ),
            startsAt: $startsAt,
            endsAt: $endsAt,
            evaluatedAt: $evaluatedAt,
        );

        $this->assertTrue($resolved?->is($subject));

        $context = app(TestEligibilityUserPolicyProvider::class)->lastContext;

        $this->assertInstanceOf(BookingSubjectEligibilityContext::class, $context);
        $this->assertTrue($context->service->is($service));
        $this->assertTrue($context->subject->is($subject));
        $this->assertTrue($context->startsAt->equalTo($startsAt));
        $this->assertTrue($context->endsAt->equalTo($endsAt));
        $this->assertTrue($context->evaluatedAt->equalTo($evaluatedAt));
        $this->assertSame(['allow' => true], $context->policy);
    }

    public function test_booking_rule_guard_fails_closed_when_subject_policy_rejects_the_subject(): void
    {
        $service = BookableService::factory()->create([
            'booking_subject_key' => TestEligibilityUserSubjectProvider::KEY,
            'booking_subject_policy' => [
                'allow' => false,
            ],
        ]);
        $subject = User::factory()->create();

        $this->expectException(DomainException::class);

        app(BookableServiceBookingRuleGuard::class)->assertSatisfied(
            service: $service,
            booking: new AppointmentBookingData(
                primaryAttendee: $subject,
                source: 'crm',
            ),
            startsAt: CarbonImmutable::parse('2026-10-15 14:00:00 UTC'),
            endsAt: CarbonImmutable::parse('2026-10-15 15:00:00 UTC'),
            evaluatedAt: CarbonImmutable::parse('2026-10-01 20:00:00 UTC'),
        );
    }

    public function test_setup_validation_reports_missing_and_invalid_subject_eligibility_policies(): void
    {
        config()->set('scheduling.public.configured', false);
        config()->set('scheduling.public.enabled', false);

        BookableService::factory()->create([
            'booking_subject_key' => BookableService::BOOKING_SUBJECT_CONTACT,
            'booking_subject_policy' => [
                'allow' => true,
            ],
        ]);

        BookableService::factory()->create([
            'booking_subject_key' => TestEligibilityUserSubjectProvider::KEY,
            'booking_subject_policy' => [
                'allow' => 'yes',
            ],
        ]);

        $codes = collect(iterator_to_array(
            app(SchedulingSetupValidationContributor::class)->findings(),
            false,
        ))->pluck('code')->all();

        $this->assertContains(
            'scheduling.booking_subject_eligibility_provider_missing',
            $codes,
        );
        $this->assertContains('scheduling.booking_subject_policy_invalid', $codes);
    }
}

final class TestEligibilityUserSubjectProvider implements BookingSubjectProvider
{
    public const KEY = 'eligibility_test_user';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Eligibility test user';
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

final class TestEligibilityUserPolicyProvider implements BookingSubjectEligibilityProvider
{
    public ?BookingSubjectEligibilityContext $lastContext = null;

    public function key(): string
    {
        return TestEligibilityUserSubjectProvider::KEY;
    }

    public function validatePolicy(array $policy): void
    {
        if (array_keys($policy) !== ['allow'] || ! is_bool($policy['allow'])) {
            throw new InvalidArgumentException('Test subject policy requires one boolean allow value.');
        }
    }

    public function assertEligible(BookingSubjectEligibilityContext $context): void
    {
        $this->validatePolicy($context->policy);
        $this->lastContext = $context;

        if ($context->policy['allow'] !== true) {
            throw new DomainException('The selected test booking subject is not eligible.');
        }
    }
}