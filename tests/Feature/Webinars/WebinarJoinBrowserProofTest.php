<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Support\WebinarJoinBrowserProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarJoinBrowserProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_proof_is_compact_versioned_url_safe_and_repeatably_valid(): void
    {
        $registration = $this->registrationEndingAt(now()->addHours(2));
        $proof = app(WebinarJoinBrowserProof::class)->issue($registration);

        $this->assertMatchesRegularExpression(
            '/^v1\.[0-9a-f]{8}\.[A-Za-z0-9_-]{22}$/D',
            $proof,
        );
        $this->assertLessThanOrEqual(34, strlen($proof));
        $this->assertStringNotContainsString('=', $proof);
        $this->assertStringNotContainsString('+', $proof);
        $this->assertStringNotContainsString('/', $proof);

        $this->assertTrue(
            app(WebinarJoinBrowserProof::class)->validFor($proof, $registration),
        );
        $this->assertTrue(
            app(WebinarJoinBrowserProof::class)->validFor($proof, $registration),
        );
    }

    public function test_proof_is_bound_to_registration_and_current_join_token(): void
    {
        $webinar = Webinar::factory()->create([
            'ends_at' => now()->addHours(2),
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create();

        $otherRegistration = WebinarRegistration::factory()
            ->for($webinar)
            ->create();

        $proof = app(WebinarJoinBrowserProof::class)->issue($registration);

        $this->assertFalse(
            app(WebinarJoinBrowserProof::class)->validFor(
                $proof,
                $otherRegistration,
            ),
        );

        $registration->forceFill([
            'join_token' => 'replacement-join-token',
        ])->save();

        $this->assertFalse(
            app(WebinarJoinBrowserProof::class)->validFor(
                $proof,
                $registration->refresh(),
            ),
        );
    }

    public function test_tampered_malformed_and_unsupported_proofs_are_rejected(): void
    {
        $registration = $this->registrationEndingAt(now()->addHours(2));
        $proof = app(WebinarJoinBrowserProof::class)->issue($registration);
        $lastCharacter = substr($proof, -1);
        $tampered = substr($proof, 0, -1).($lastCharacter === 'A' ? 'B' : 'A');
        $unsupportedVersion = preg_replace('/^v1\./', 'v2.', $proof);

        foreach (array_filter([
            '',
            'invalid-browser-proof',
            'v1.bad-expiration.bad-tag',
            'v1.00000000.bad-tag',
            $tampered,
            $unsupportedVersion,
        ], fn (mixed $candidate): bool => is_string($candidate)) as $candidate) {
            $this->assertFalse(
                app(WebinarJoinBrowserProof::class)->validFor(
                    $candidate,
                    $registration,
                ),
                "Expected proof [{$candidate}] to be rejected.",
            );
        }
    }

    public function test_proof_expires_after_webinar_end_plus_configured_grace(): void
    {
        $this->travelTo(now()->startOfSecond());

        Config::set(
            'webinars.registration.join_confirmation.browser_proof_grace_minutes',
            1,
        );

        $registration = $this->registrationEndingAt(now()->addMinutes(5));
        $proof = app(WebinarJoinBrowserProof::class)->issue($registration);

        $this->travel(5)->minutes();

        $this->assertTrue(
            app(WebinarJoinBrowserProof::class)->validFor($proof, $registration),
        );

        $this->travel(2)->minutes();

        $this->assertFalse(
            app(WebinarJoinBrowserProof::class)->validFor($proof, $registration),
        );
    }

    public function test_proof_uses_fallback_lifetime_when_event_expiry_is_not_future(): void
    {
        $this->travelTo(now()->startOfSecond());

        Config::set(
            'webinars.registration.join_confirmation.browser_proof_fallback_days',
            1,
        );

        $registration = $this->registrationEndingAt(now()->subDays(2));
        $proof = app(WebinarJoinBrowserProof::class)->issue($registration);

        $this->travel(23)->hours();

        $this->assertTrue(
            app(WebinarJoinBrowserProof::class)->validFor($proof, $registration),
        );

        $this->travel(2)->hours();

        $this->assertFalse(
            app(WebinarJoinBrowserProof::class)->validFor($proof, $registration),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set(
            'app.key',
            'base64:'.base64_encode(str_repeat('k', 32)),
        );
        Config::set(
            'webinars.registration.join_confirmation.browser_proof_version',
            1,
        );
        Config::set(
            'webinars.registration.join_confirmation.browser_proof_tag_bytes',
            16,
        );
        Config::set(
            'webinars.registration.join_confirmation.browser_proof_grace_minutes',
            1440,
        );
        Config::set(
            'webinars.registration.join_confirmation.browser_proof_fallback_days',
            30,
        );
    }

    private function registrationEndingAt(mixed $endsAt): WebinarRegistration
    {
        $webinar = Webinar::factory()->create([
            'starts_at' => now()->subHour(),
            'ends_at' => $endsAt,
        ]);

        return WebinarRegistration::factory()
            ->for($webinar)
            ->create();
    }
}