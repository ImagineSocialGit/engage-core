<?php

namespace App\Modules\Messaging\Services\DeliveryIssues;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MessageDeliveryIssueReviewService
{
    public const FILTER_CHANNELS = [
        MessageChannel::Email->value => 'Email',
        MessageChannel::Sms->value => 'Text messages',
    ];

    public const FILTER_REASONS = [
        MessageSuppression::REASON_BOUNCE => 'Delivery failed',
        MessageSuppression::REASON_INVALID_DESTINATION => 'Invalid destination',
        MessageSuppression::REASON_PROVIDER => 'Provider blocked delivery',
        MessageSuppression::REASON_REPEATED_FAILURE => 'Repeated failures',
        MessageSuppression::REASON_COMPLAINT => 'Recipient complaint',
        MessageSuppression::REASON_MANUAL => 'Stopped manually',
    ];

    /**
     * Return active suppressions that still match at least one Contact's
     * current destination.
     *
     * Historical suppressions remain durable after a Contact changes their
     * email address or phone number, but they no longer remain in the operator
     * review queue unless that destination is current for a Contact.
     *
     * @return Builder<MessageSuppression>
     */
    public function query(array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($filters);

        $query = MessageSuppression::query()
            ->active()
            ->whereNull('meta->delivery_issue_review->dismissed_at')
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('channel', MessageChannel::Email->value)
                            ->whereExists(function (QueryBuilder $contacts): void {
                                $contacts
                                    ->selectRaw('1')
                                    ->from('contacts')
                                    ->whereNull('contacts.deleted_at')
                                    ->whereNotNull('contacts.email')
                                    ->whereRaw(
                                        'LOWER(contacts.email) = LOWER(message_suppressions.destination)',
                                    );
                            });
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('channel', MessageChannel::Sms->value)
                            ->whereExists(function (QueryBuilder $contacts): void {
                                $contacts
                                    ->selectRaw('1')
                                    ->from('contacts')
                                    ->whereNull('contacts.deleted_at')
                                    ->whereNotNull('contacts.phone')
                                    ->whereColumn(
                                        'contacts.phone',
                                        'message_suppressions.destination',
                                    );
                            });
                    });
            })
            ->latest('suppressed_at')
            ->latest('id');

        if ($filters['channel'] !== null) {
            $query->where('channel', $filters['channel']);
        }

        if ($filters['reason'] !== null) {
            $query->where('reason', $filters['reason']);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{channel: ?string, reason: ?string}
     */
    public function normalizeFilters(array $filters): array
    {
        $channel = is_string($filters['channel'] ?? null)
            ? trim((string) $filters['channel'])
            : '';
        $reason = is_string($filters['reason'] ?? null)
            ? trim((string) $filters['reason'])
            : '';

        return [
            'channel' => array_key_exists($channel, self::FILTER_CHANNELS)
                ? $channel
                : null,
            'reason' => array_key_exists($reason, self::FILTER_REASONS)
                ? $reason
                : null,
        ];
    }

    /**
     * @return array{channels: array<string, string>, reasons: array<string, string>}
     */
    public function filterOptions(): array
    {
        return [
            'channels' => self::FILTER_CHANNELS,
            'reasons' => self::FILTER_REASONS,
        ];
    }

    /**
     * @return Collection<int, MessageSuppression>
     */
    public function forContact(Contact $contact): Collection
    {
        $email = $this->normalizeEmail($contact->email);
        $phone = $this->normalizeDestination($contact->phone);

        if ($email === null && $phone === null) {
            return collect();
        }

        return MessageSuppression::query()
            ->active()
            ->whereNull('meta->delivery_issue_review->dismissed_at')
            ->where(function (Builder $query) use ($email, $phone): void {
                $hasCondition = false;

                if ($email !== null) {
                    $query->where(function (Builder $query) use ($email): void {
                        $query
                            ->where('channel', MessageChannel::Email->value)
                            ->whereRaw('LOWER(destination) = ?', [$email]);
                    });

                    $hasCondition = true;
                }

                if ($phone !== null) {
                    $method = $hasCondition ? 'orWhere' : 'where';

                    $query->{$method}(function (Builder $query) use ($phone): void {
                        $query
                            ->where('channel', MessageChannel::Sms->value)
                            ->where('destination', $phone);
                    });
                }
            })
            ->latest('suppressed_at')
            ->latest('id')
            ->get();
    }

    public function isCurrentIssue(MessageSuppression $suppression): bool
    {
        if (! $suppression->isActive() || $this->isDismissed($suppression)) {
            return false;
        }

        return $this->contactsFor($suppression)->isNotEmpty();
    }

    public function isCurrentIssueForContact(
        MessageSuppression $suppression,
        Contact $contact,
    ): bool {
        return $suppression->isActive()
            && ! $this->isDismissed($suppression)
            && ! $contact->trashed()
            && $this->matches($contact, $suppression);
    }

    /**
     * @param Collection<int, MessageSuppression> $suppressions
     * @return Collection<int, array{
     *     suppression: MessageSuppression,
     *     contacts: Collection<int, Contact>,
     *     reason_label: string,
     *     action_guidance: string,
     *     provider_detail: ?string,
     *     provider_label: ?string,
     *     bounce_type_label: ?string,
     *     suppressed_at_label: ?string,
     *     can_release: bool
     * }>
     */
    public function present(Collection $suppressions): Collection
    {
        if ($suppressions->isEmpty()) {
            return collect();
        }

        $contacts = $this->contactsMatching($suppressions);

        return $suppressions
            ->map(function (MessageSuppression $suppression) use ($contacts): array {
                $matchingContacts = $contacts
                    ->filter(fn (Contact $contact): bool => $this->matches(
                        contact: $contact,
                        suppression: $suppression,
                    ))
                    ->values();

                return [
                    'suppression' => $suppression,
                    'contacts' => $matchingContacts,
                    'contact' => $matchingContacts->first(),
                    'reason_label' => $this->reasonLabelFor($suppression),
                    'problem_label' => $suppression->channel === MessageChannel::Email->value
                        ? 'Email could not be delivered'
                        : 'Text message could not be delivered',
                    'edit_field' => $suppression->channel === MessageChannel::Email->value
                        ? 'email'
                        : 'phone',
                    'action_guidance' => $this->actionGuidanceFor($suppression),
                    'provider_detail' => $this->providerDetailFor($suppression),
                    'provider_label' => $this->providerLabelFor($suppression),
                    'bounce_type_label' => $this->bounceTypeLabelFor($suppression),
                    'suppressed_at_label' => $this->suppressedAtLabel($suppression),
                    'can_release' => $this->canRelease($suppression),
                ];
            })
            ->values();
    }

    public function isDismissed(MessageSuppression $suppression): bool
    {
        $dismissedAt = data_get(
            $suppression->meta,
            'delivery_issue_review.dismissed_at',
        );

        return is_string($dismissedAt) && trim($dismissedAt) !== '';
    }

    public function dismiss(
        MessageSuppression $suppression,
        ?int $actorUserId,
    ): MessageSuppression {
        return DB::transaction(function () use (
            $suppression,
            $actorUserId,
        ): MessageSuppression {
            $locked = MessageSuppression::query()
                ->lockForUpdate()
                ->findOrFail($suppression->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $review = data_get($meta, 'delivery_issue_review', []);
            $review = is_array($review) ? $review : [];

            $review['dismissed_at'] = now()->toIso8601String();
            $review['dismissed_by_user_id'] = $actorUserId;

            data_set($meta, 'delivery_issue_review', $review);

            $locked->forceFill(['meta' => $meta])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    public function canRelease(MessageSuppression $suppression): bool
    {
        return $suppression->reason !== MessageSuppression::REASON_COMPLAINT;
    }

    public function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            MessageSuppression::REASON_BOUNCE => 'The message could not be delivered.',
            MessageSuppression::REASON_COMPLAINT => 'The recipient reported a message as unwanted.',
            MessageSuppression::REASON_MANUAL => 'Messaging to this destination was stopped manually.',
            MessageSuppression::REASON_PROVIDER => 'The email provider is currently blocking delivery.',
            MessageSuppression::REASON_INVALID_DESTINATION => 'The email address does not appear to be valid.',
            MessageSuppression::REASON_REPEATED_FAILURE => 'Messages to this destination have failed repeatedly.',
            default => 'Messages cannot currently be delivered to this destination.',
        };
    }

    public function reasonLabelFor(MessageSuppression $suppression): string
    {
        if ($suppression->reason !== MessageSuppression::REASON_BOUNCE) {
            return $this->reasonLabel($suppression->reason);
        }

        $text = $this->bounceSearchText($suppression);

        foreach ([
            'does not exist',
            "doesn't exist",
            'not exist',
            'unknown user',
            'unknown recipient',
            'no such user',
            'user unknown',
            'recipient not found',
            'mailbox not found',
            'invalid recipient',
            'invalid address',
            'recipient address rejected',
        ] as $signal) {
            if (str_contains($text, $signal)) {
                return 'The email address does not appear to exist.';
            }
        }

        foreach ([
            'mailbox full',
            'mailbox is full',
            'quota exceeded',
            'over quota',
            'storage full',
        ] as $signal) {
            if (str_contains($text, $signal)) {
                return 'The recipient’s mailbox is full.';
            }
        }

        foreach ([
            'suppressed',
            'suppression',
            'previous bounce',
            'previously bounced',
            'recent history',
        ] as $signal) {
            if (str_contains($text, $signal)) {
                return 'Delivery is blocked because this address has failed previously.';
            }
        }

        foreach ([
            'dns',
            'domain not found',
            'domain does not exist',
            'no mx',
            'mx record',
            'could not resolve',
        ] as $signal) {
            if (str_contains($text, $signal)) {
                return 'The recipient’s email domain cannot currently receive mail.';
            }
        }

        foreach ([
            'blocked',
            'rejected',
            'denied',
            'policy',
            'spam',
        ] as $signal) {
            if (str_contains($text, $signal)) {
                return 'The recipient’s mail server rejected the message.';
            }
        }

        return $this->reasonLabel($suppression->reason);
    }

    public function actionGuidanceFor(MessageSuppression $suppression): string
    {
        $text = $this->bounceSearchText($suppression);

        if ($suppression->reason === MessageSuppression::REASON_BOUNCE) {
            foreach ([
                'does not exist',
                "doesn't exist",
                'not exist',
                'unknown user',
                'unknown recipient',
                'no such user',
                'user unknown',
                'recipient not found',
                'mailbox not found',
                'invalid recipient',
                'invalid address',
                'recipient address rejected',
            ] as $signal) {
                if (str_contains($text, $signal)) {
                    return 'Check the email address for a typo or replace it with a working address.';
                }
            }

            if (str_contains($text, 'mailbox full') || str_contains($text, 'quota exceeded')) {
                return 'The address may work again later. Use another contact method if the message is time-sensitive.';
            }

            if (str_contains($text, 'dns') || str_contains($text, 'domain not found') || str_contains($text, 'no mx')) {
                return 'Check the email domain for a typo. If it is correct, use another contact method until the recipient fixes their email service.';
            }
        }

        return $suppression->channel === MessageChannel::Email->value
            ? 'Check the email address and correct it if it is wrong. Otherwise verify the address before allowing email again.'
            : 'Check the phone number and correct it if it is wrong. Otherwise verify the number before allowing messages again.';
    }

    public function providerDetailFor(MessageSuppression $suppression): ?string
    {
        $message = data_get($suppression->meta, 'bounce.message');

        return is_string($message) && trim($message) !== ''
            ? trim($message)
            : null;
    }


    public function providerLabelFor(MessageSuppression $suppression): ?string
    {
        $provider = $this->normalizeDestination($suppression->provider);

        return match ($provider) {
            MessageSuppression::PROVIDER_RESEND => 'Resend',
            MessageSuppression::PROVIDER_TELNYX => 'Telnyx',
            MessageSuppression::PROVIDER_TWILIO => 'Twilio',
            null => null,
            default => str($provider)->headline()->toString(),
        };
    }

    public function bounceTypeLabelFor(MessageSuppression $suppression): ?string
    {
        if ($suppression->reason !== MessageSuppression::REASON_BOUNCE) {
            return null;
        }

        $parts = collect([
            data_get($suppression->meta, 'bounce.type'),
            data_get($suppression->meta, 'bounce.subtype'),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (mixed $value): string => str((string) $value)->headline()->toString())
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(' · ');
    }

    private function suppressedAtLabel(MessageSuppression $suppression): ?string
    {
        if ($suppression->suppressed_at === null) {
            return null;
        }

        return $suppression->suppressed_at
            ->copy()
            ->setTimezone(config('client.timezone', config('app.timezone', 'UTC')))
            ->format('M j, Y g:i A T');
    }

    private function bounceSearchText(MessageSuppression $suppression): string
    {
        return mb_strtolower(implode(' ', array_filter([
            data_get($suppression->meta, 'bounce.type'),
            data_get($suppression->meta, 'bounce.subtype'),
            data_get($suppression->meta, 'bounce.message'),
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
    }

    /**
     * @return Collection<int, Contact>
     */
    private function contactsFor(MessageSuppression $suppression): Collection
    {
        return $this->contactsMatching(collect([$suppression]));
    }

    /**
     * @param Collection<int, MessageSuppression> $suppressions
     * @return Collection<int, Contact>
     */
    private function contactsMatching(Collection $suppressions): Collection
    {
        $emails = $suppressions
            ->where('channel', MessageChannel::Email->value)
            ->pluck('destination')
            ->map(fn (mixed $value): ?string => $this->normalizeEmail($value))
            ->filter()
            ->unique()
            ->values();

        $phones = $suppressions
            ->where('channel', MessageChannel::Sms->value)
            ->pluck('destination')
            ->map(fn (mixed $value): ?string => $this->normalizeDestination($value))
            ->filter()
            ->unique()
            ->values();

        if ($emails->isEmpty() && $phones->isEmpty()) {
            return collect();
        }

        return Contact::query()
            ->where(function (Builder $query) use ($emails, $phones): void {
                $hasCondition = false;

                if ($emails->isNotEmpty()) {
                    $query->whereIn(
                        DB::raw('LOWER(email)'),
                        $emails->all(),
                    );

                    $hasCondition = true;
                }

                if ($phones->isNotEmpty()) {
                    $method = $hasCondition ? 'orWhereIn' : 'whereIn';

                    $query->{$method}('phone', $phones->all());
                }
            })
            ->orderBy('id')
            ->get();
    }

    private function matches(
        Contact $contact,
        MessageSuppression $suppression,
    ): bool {
        if ($suppression->channel === MessageChannel::Email->value) {
            return $this->normalizeEmail($contact->email)
                === $this->normalizeEmail($suppression->destination);
        }

        if ($suppression->channel === MessageChannel::Sms->value) {
            return $this->normalizeDestination($contact->phone)
                === $this->normalizeDestination($suppression->destination);
        }

        return false;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $value = $this->normalizeDestination($value);

        return $value !== null ? mb_strtolower($value) : null;
    }

    private function normalizeDestination(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}