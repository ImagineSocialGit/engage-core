<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Data\DestinationVerificationChallenge;
use App\Modules\Scheduling\Data\DestinationVerificationProof;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Support\DestinationVerification\Contracts\DestinationVerificationTransport;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PublicBookingDestinationVerificationService
{
    public const SURFACE = 'scheduling_public_booking';

    public const PURPOSE = 'transactional';

    public const SCOPE = 'scheduling_public_booking';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    private const SUPPORTED_CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_SMS,
    ];

    public function __construct(
        private readonly DestinationVerificationTransport $transport,
    ) {}

    /**
     * @return array<int, string>
     */
    public function availableChannels(): array
    {
        return array_values(array_intersect(
            self::SUPPORTED_CHANNELS,
            $this->transport->availableChannels(
                surface: self::SURFACE,
                purpose: self::PURPOSE,
                scope: self::SCOPE,
            ),
        ));
    }

    public function issue(
        BookableSlotOffer $offer,
        string $sessionId,
        string $channel,
        string $destination,
        ?string $sourceIp = null,
    ): DestinationVerificationChallenge {
        $now = CarbonImmutable::now('UTC');
        $this->assertVerifiableOffer($offer, $now);

        $channel = $this->normalizeChannel($channel);

        if (! in_array($channel, $this->availableChannels(), true)) {
            throw new DomainException(
                "Destination verification channel [{$channel}] is unavailable.",
            );
        }

        $destination = $this->transport->normalizeDestination(
            channel: $channel,
            destination: $destination,
        );

        if (! is_string($destination) || trim($destination) === '') {
            throw new InvalidArgumentException(
                'Destination verification requires a valid reachable destination.',
            );
        }

        $destination = trim($destination);
        $sessionHash = $this->sessionHash($sessionId);
        $this->assertIssueRateLimits(
            offer: $offer,
            sessionHash: $sessionHash,
            channel: $channel,
            destination: $destination,
            sourceIp: $sourceIp,
        );

        $challengeId = (string) Str::uuid();
        $destinationHash = $this->destinationHash($channel, $destination);
        $code = $this->verificationCode(
            challengeId: $challengeId,
            offerId: (string) $offer->offer_id,
            sessionHash: $sessionHash,
            channel: $channel,
            destinationHash: $destinationHash,
        );
        $expiresAt = $this->challengeExpiresAt($offer, $now);
        $resendAvailableAt = $now->addSeconds($this->resendCooldownSeconds());
        $state = [
            'challenge_id' => $challengeId,
            'offer_id' => (string) $offer->offer_id,
            'session_hash' => $sessionHash,
            'channel' => $channel,
            'destination' => $destination,
            'destination_hash' => $destinationHash,
            'code_hash' => $this->codeHash($challengeId, $code),
            'attempt_count' => 0,
            'send_count' => 1,
            'issued_at' => $now->toISOString(),
            'last_sent_at' => $now->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
        ];

        $this->putChallenge($challengeId, $state, $expiresAt, $now);

        try {
            $this->transport->send(
                recipient: $offer,
                surface: self::SURFACE,
                channel: $channel,
                purpose: self::PURPOSE,
                scope: self::SCOPE,
                destination: $destination,
                code: $code,
                dedupeKey: $this->deliveryDedupeKey($challengeId, 1),
                sourceIp: $sourceIp,
            );
        } catch (\Throwable $exception) {
            Cache::forget($this->challengeKey($challengeId));

            throw $exception;
        }

        return new DestinationVerificationChallenge(
            challengeId: $challengeId,
            channel: $channel,
            destination: $destination,
            maskedDestination: $this->maskedDestination($channel, $destination),
            expiresAt: $expiresAt,
            resendAvailableAt: $resendAvailableAt->lessThan($expiresAt)
                ? $resendAvailableAt
                : $expiresAt,
        );
    }

    public function resend(
        BookableSlotOffer $offer,
        string $sessionId,
        string $challengeId,
        ?string $sourceIp = null,
    ): DestinationVerificationChallenge {
        $challengeId = $this->challengeId($challengeId);

        return Cache::lock($this->challengeLockKey($challengeId), 5)
            ->block(3, function () use (
                $offer,
                $sessionId,
                $challengeId,
                $sourceIp,
            ): DestinationVerificationChallenge {
                $now = CarbonImmutable::now('UTC');
                $this->assertVerifiableOffer($offer, $now);
                $state = $this->challengeState($challengeId, $now);
                $this->assertChallengeBinding($state, $offer, $sessionId);

                $sendCount = (int) ($state['send_count'] ?? 0);

                if ($sendCount >= $this->maximumSends()) {
                    throw new DomainException(
                        'Destination verification resend limit has been reached.',
                    );
                }

                $lastSentAt = CarbonImmutable::parse(
                    (string) ($state['last_sent_at'] ?? $state['issued_at']),
                    'UTC',
                )->utc();
                $resendAvailableAt = $lastSentAt->addSeconds(
                    $this->resendCooldownSeconds(),
                );

                if ($resendAvailableAt->greaterThan($now)) {
                    throw new DomainException(
                        'Destination verification code cannot be resent yet.',
                    );
                }

                $this->assertSingleRateLimit(
                    key: $this->rateLimitKey('challenge', hash('sha256', $challengeId)),
                    maximumAttempts: $this->rateLimit('per_challenge_per_hour', 4),
                    message: 'Too many destination verification resends. Try again later.',
                );

                $channel = $this->normalizeChannel((string) $state['channel']);
                $destination = trim((string) $state['destination']);
                $sessionHash = $this->sessionHash($sessionId);
                $this->assertIssueRateLimits(
                    offer: $offer,
                    sessionHash: $sessionHash,
                    channel: $channel,
                    destination: $destination,
                    sourceIp: $sourceIp,
                );

                $code = $this->verificationCode(
                    challengeId: $challengeId,
                    offerId: (string) $state['offer_id'],
                    sessionHash: (string) $state['session_hash'],
                    channel: $channel,
                    destinationHash: (string) $state['destination_hash'],
                );
                $newSendCount = $sendCount + 1;
                $updated = $state;
                $updated['attempt_count'] = 0;
                $updated['send_count'] = $newSendCount;
                $updated['last_sent_at'] = $now->toISOString();
                $expiresAt = CarbonImmutable::parse(
                    (string) $state['expires_at'],
                    'UTC',
                )->utc();

                $this->putChallenge($challengeId, $updated, $expiresAt, $now);

                try {
                    $this->transport->send(
                        recipient: $offer,
                        surface: self::SURFACE,
                        channel: $channel,
                        purpose: self::PURPOSE,
                        scope: self::SCOPE,
                        destination: $destination,
                        code: $code,
                        dedupeKey: $this->deliveryDedupeKey(
                            $challengeId,
                            $newSendCount,
                        ),
                        sourceIp: $sourceIp,
                    );
                } catch (\Throwable $exception) {
                    $this->putChallenge($challengeId, $state, $expiresAt, $now);

                    throw $exception;
                }

                $nextResendAt = $now->addSeconds($this->resendCooldownSeconds());

                return new DestinationVerificationChallenge(
                    challengeId: $challengeId,
                    channel: $channel,
                    destination: $destination,
                    maskedDestination: $this->maskedDestination($channel, $destination),
                    expiresAt: $expiresAt,
                    resendAvailableAt: $nextResendAt->lessThan($expiresAt)
                        ? $nextResendAt
                        : $expiresAt,
                );
            });
    }

    public function verify(
        BookableSlotOffer $offer,
        string $sessionId,
        string $challengeId,
        string $code,
    ): DestinationVerificationProof {
        $challengeId = $this->challengeId($challengeId);

        return Cache::lock($this->challengeLockKey($challengeId), 5)
            ->block(3, function () use (
                $offer,
                $sessionId,
                $challengeId,
                $code,
            ): DestinationVerificationProof {
                $now = CarbonImmutable::now('UTC');
                $this->assertVerifiableOffer($offer, $now);
                $state = $this->challengeState($challengeId, $now);
                $this->assertChallengeBinding($state, $offer, $sessionId);

                $attemptCount = (int) ($state['attempt_count'] ?? 0);

                if ($attemptCount >= $this->maximumCodeAttempts()) {
                    throw new DomainException(
                        'Destination verification attempt limit has been reached.',
                    );
                }

                $code = trim($code);
                $expectedCodeHash = (string) ($state['code_hash'] ?? '');
                $valid = $this->validCodeShape($code)
                    && $expectedCodeHash !== ''
                    && hash_equals(
                        $expectedCodeHash,
                        $this->codeHash($challengeId, $code),
                    );

                if (! $valid) {
                    $state['attempt_count'] = $attemptCount + 1;
                    $expiresAt = CarbonImmutable::parse(
                        (string) $state['expires_at'],
                        'UTC',
                    )->utc();
                    $this->putChallenge($challengeId, $state, $expiresAt, $now);

                    throw new DomainException('Destination verification code is invalid.');
                }

                $proofToken = Str::random(64);
                $proofExpiresAt = $this->proofExpiresAt($offer, $now);
                $proofState = [
                    'offer_id' => (string) $offer->offer_id,
                    'session_hash' => $this->sessionHash($sessionId),
                    'channel' => (string) $state['channel'],
                    'destination_hash' => (string) $state['destination_hash'],
                    'verified_at' => $now->toISOString(),
                    'expires_at' => $proofExpiresAt->toISOString(),
                ];

                Cache::put(
                    $this->proofKey($proofToken),
                    $proofState,
                    max(1, $proofExpiresAt->getTimestamp() - $now->getTimestamp()),
                );
                Cache::forget($this->challengeKey($challengeId));

                return new DestinationVerificationProof(
                    token: $proofToken,
                    channel: (string) $state['channel'],
                    destination: (string) $state['destination'],
                    verifiedAt: $now,
                    expiresAt: $proofExpiresAt,
                );
            });
    }

    public function hasValidProof(
        BookableSlotOffer $offer,
        string $sessionId,
        string $proofToken,
    ): bool {
        if (! $offer->isActiveAt()) {
            return false;
        }

        $proofToken = trim($proofToken);

        if ($proofToken === '') {
            return false;
        }

        $state = Cache::get($this->proofKey($proofToken));

        return is_array($state)
            && $this->proofMatches($state, $offer, $sessionId);
    }

    public function consumeProof(
        BookableSlotOffer $offer,
        string $sessionId,
        string $proofToken,
    ): bool {
        $proofToken = trim($proofToken);

        if ($proofToken === '') {
            return false;
        }

        $proofKey = $this->proofKey($proofToken);

        return Cache::lock($this->proofLockKey($proofToken), 5)
            ->block(3, function () use (
                $offer,
                $sessionId,
                $proofKey,
            ): bool {
                $state = Cache::get($proofKey);

                if (! is_array($state)
                    || ! $offer->isActiveAt()
                    || ! $this->proofMatches($state, $offer, $sessionId)
                ) {
                    return false;
                }

                Cache::forget($proofKey);

                return true;
            });
    }

    private function assertVerifiableOffer(
        BookableSlotOffer $offer,
        CarbonImmutable $now,
    ): void {
        if (! $offer->exists || $offer->getKey() === null) {
            throw new InvalidArgumentException(
                'Destination verification requires a persisted slot offer.',
            );
        }

        if ($offer->isRescheduleOffer()) {
            throw new DomainException(
                'Destination verification is not available for reschedule offers.',
            );
        }

        if (! $offer->isActiveAt($now)) {
            throw new DomainException(
                'The selected booking offer is no longer available.',
            );
        }

        $service = $offer->bookableService()->first();

        if (! $service instanceof BookableService
            || $service->status !== BookableService::STATUS_ACTIVE
            || ! (bool) $service->is_public
        ) {
            throw new DomainException(
                'The selected booking service is no longer available.',
            );
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function assertChallengeBinding(
        array $state,
        BookableSlotOffer $offer,
        string $sessionId,
    ): void {
        if (! hash_equals(
            (string) ($state['offer_id'] ?? ''),
            (string) $offer->offer_id,
        ) || ! hash_equals(
            (string) ($state['session_hash'] ?? ''),
            $this->sessionHash($sessionId),
        )) {
            throw new DomainException(
                'Destination verification challenge does not match this booking session.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeState(
        string $challengeId,
        CarbonImmutable $now,
    ): array {
        $state = Cache::get($this->challengeKey($challengeId));

        if (! is_array($state)) {
            throw new DomainException(
                'Destination verification challenge is missing or expired.',
            );
        }

        $expiresAt = CarbonImmutable::parse(
            (string) ($state['expires_at'] ?? ''),
            'UTC',
        )->utc();

        if (! $expiresAt->greaterThan($now)) {
            Cache::forget($this->challengeKey($challengeId));

            throw new DomainException(
                'Destination verification challenge has expired.',
            );
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function proofMatches(
        array $state,
        BookableSlotOffer $offer,
        string $sessionId,
    ): bool {
        $expiresAtValue = $state['expires_at'] ?? null;

        if (! is_string($expiresAtValue) || trim($expiresAtValue) === '') {
            return false;
        }

        $expiresAt = CarbonImmutable::parse($expiresAtValue, 'UTC')->utc();

        return $expiresAt->isFuture()
            && hash_equals(
                (string) ($state['offer_id'] ?? ''),
                (string) $offer->offer_id,
            )
            && hash_equals(
                (string) ($state['session_hash'] ?? ''),
                $this->sessionHash($sessionId),
            );
    }

    private function challengeExpiresAt(
        BookableSlotOffer $offer,
        CarbonImmutable $now,
    ): CarbonImmutable {
        $configured = $now->addSeconds($this->challengeTtlSeconds());
        $offerExpiry = CarbonImmutable::instance($offer->expires_at)->utc();
        $expiresAt = $configured->lessThan($offerExpiry)
            ? $configured
            : $offerExpiry;

        if (! $expiresAt->greaterThan($now)) {
            throw new DomainException(
                'The selected booking offer expires too soon to verify.',
            );
        }

        return $expiresAt;
    }

    private function proofExpiresAt(
        BookableSlotOffer $offer,
        CarbonImmutable $now,
    ): CarbonImmutable {
        $configured = $now->addSeconds($this->proofTtlSeconds());
        $offerExpiry = CarbonImmutable::instance($offer->expires_at)->utc();

        return $configured->lessThan($offerExpiry)
            ? $configured
            : $offerExpiry;
    }

    private function assertIssueRateLimits(
        BookableSlotOffer $offer,
        string $sessionHash,
        string $channel,
        string $destination,
        ?string $sourceIp,
    ): void {
        $keys = [
            [
                $this->rateLimitKey(
                    'destination',
                    $this->destinationHash($channel, $destination),
                ),
                $this->rateLimit('per_destination_per_hour', 6),
            ],
            [
                $this->rateLimitKey(
                    'offer_session',
                    hash('sha256', (string) $offer->offer_id.'|'.$sessionHash),
                ),
                $this->rateLimit('per_offer_session_per_hour', 8),
            ],
        ];

        if (is_string($sourceIp) && trim($sourceIp) !== '') {
            $keys[] = [
                $this->rateLimitKey('ip', hash('sha256', trim($sourceIp))),
                $this->rateLimit('per_ip_per_hour', 20),
            ];
        }

        foreach ($keys as [$key, $maximumAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maximumAttempts)) {
                throw new DomainException(
                    'Too many destination verification requests. Try again later.',
                );
            }
        }

        foreach ($keys as [$key]) {
            RateLimiter::hit($key, 3600);
        }
    }

    private function assertSingleRateLimit(
        string $key,
        int $maximumAttempts,
        string $message,
    ): void {
        if (RateLimiter::tooManyAttempts($key, $maximumAttempts)) {
            throw new DomainException($message);
        }

        RateLimiter::hit($key, 3600);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function putChallenge(
        string $challengeId,
        array $state,
        CarbonImmutable $expiresAt,
        CarbonImmutable $now,
    ): void {
        Cache::put(
            $this->challengeKey($challengeId),
            $state,
            max(1, $expiresAt->getTimestamp() - $now->getTimestamp()),
        );
    }

    private function verificationCode(
        string $challengeId,
        string $offerId,
        string $sessionHash,
        string $channel,
        string $destinationHash,
    ): string {
        $digits = $this->codeDigits();
        $maximum = 10 ** $digits;
        $digest = hash_hmac(
            'sha256',
            implode('|', [
                'scheduling_destination_verification',
                $challengeId,
                $offerId,
                $sessionHash,
                $channel,
                $destinationHash,
            ]),
            $this->secret(),
        );
        $number = hexdec(substr($digest, 0, 12)) % $maximum;

        return str_pad(
            (string) $number,
            $digits,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function validCodeShape(string $code): bool
    {
        return preg_match('/^\d{'.$this->codeDigits().'}$/D', $code) === 1;
    }

    private function codeHash(string $challengeId, string $code): string
    {
        return hash_hmac(
            'sha256',
            $challengeId.'|'.$code,
            $this->secret(),
        );
    }

    private function sessionHash(string $sessionId): string
    {
        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            throw new InvalidArgumentException(
                'Destination verification requires an active booking session.',
            );
        }

        return hash_hmac('sha256', $sessionId, $this->secret());
    }

    private function destinationHash(string $channel, string $destination): string
    {
        return hash_hmac(
            'sha256',
            strtolower(trim($channel)).'|'.trim($destination),
            $this->secret(),
        );
    }

    private function secret(): string
    {
        $key = config('app.key');

        if (! is_string($key) || trim($key) === '') {
            throw new InvalidArgumentException(
                'Destination verification requires APP_KEY.',
            );
        }

        return $key;
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = str_replace('-', '_', strtolower(trim($channel)));

        if (! in_array($channel, self::SUPPORTED_CHANNELS, true)) {
            throw new InvalidArgumentException(
                "Unsupported destination verification channel [{$channel}].",
            );
        }

        return $channel;
    }

    private function challengeId(string $challengeId): string
    {
        $challengeId = trim($challengeId);

        if (! Str::isUuid($challengeId)) {
            throw new InvalidArgumentException(
                'Destination verification challenge ID must be a UUID.',
            );
        }

        return $challengeId;
    }

    private function maskedDestination(string $channel, string $destination): string
    {
        if ($channel === self::CHANNEL_EMAIL) {
            [$local, $domain] = array_pad(explode('@', $destination, 2), 2, '');
            $first = $local !== '' ? mb_substr($local, 0, 1) : '*';

            return $first.'***@'.$domain;
        }

        $digits = preg_replace('/\D+/', '', $destination) ?? '';
        $lastFour = substr($digits, -4);

        return $lastFour !== '' ? '••• ••• '.$lastFour : '••••';
    }

    private function deliveryDedupeKey(string $challengeId, int $sendCount): string
    {
        return "scheduling_destination_verification:{$challengeId}:{$sendCount}";
    }

    private function challengeKey(string $challengeId): string
    {
        return "scheduling:destination_verification:challenge:{$challengeId}";
    }

    private function challengeLockKey(string $challengeId): string
    {
        return "scheduling:destination_verification:challenge_lock:{$challengeId}";
    }

    private function proofKey(string $proofToken): string
    {
        return 'scheduling:destination_verification:proof:'.hash('sha256', $proofToken);
    }

    private function proofLockKey(string $proofToken): string
    {
        return 'scheduling:destination_verification:proof_lock:'.hash('sha256', $proofToken);
    }

    private function rateLimitKey(string $kind, string $fingerprint): string
    {
        return "scheduling:destination_verification:rate:{$kind}:{$fingerprint}";
    }

    private function codeDigits(): int
    {
        return min(8, max(4, (int) config(
            'scheduling.public.destination_verification.code_digits',
            6,
        )));
    }

    private function challengeTtlSeconds(): int
    {
        return min(1800, max(60, (int) config(
            'scheduling.public.destination_verification.challenge_ttl_seconds',
            300,
        )));
    }

    private function proofTtlSeconds(): int
    {
        return min(1800, max(60, (int) config(
            'scheduling.public.destination_verification.proof_ttl_seconds',
            300,
        )));
    }

    private function maximumCodeAttempts(): int
    {
        return min(20, max(1, (int) config(
            'scheduling.public.destination_verification.max_code_attempts',
            5,
        )));
    }

    private function maximumSends(): int
    {
        return min(10, max(1, (int) config(
            'scheduling.public.destination_verification.max_sends_per_challenge',
            3,
        )));
    }

    private function resendCooldownSeconds(): int
    {
        return min(600, max(5, (int) config(
            'scheduling.public.destination_verification.resend_cooldown_seconds',
            30,
        )));
    }

    private function rateLimit(string $key, int $default): int
    {
        return min(1000, max(1, (int) config(
            "scheduling.public.destination_verification.rate_limits.{$key}",
            $default,
        )));
    }
}