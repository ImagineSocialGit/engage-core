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
    public function query(): Builder
    {
        return MessageSuppression::query()
            ->active()
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('channel', MessageChannel::Email->value)
                            ->whereExists(function (QueryBuilder $contacts): void {
                                $contacts
                                    ->selectRaw('1')
                                    ->from('contacts')
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
        if (! $suppression->isActive()) {
            return false;
        }

        return $this->contactsFor($suppression)->isNotEmpty();
    }

    /**
     * @param Collection<int, MessageSuppression> $suppressions
     * @return Collection<int, array{
     *     suppression: MessageSuppression,
     *     contacts: Collection<int, Contact>,
     *     reason_label: string,
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
                    'reason_label' => $this->reasonLabel($suppression->reason),
                    'can_release' => $this->canRelease($suppression),
                ];
            })
            ->values();
    }

    public function canRelease(MessageSuppression $suppression): bool
    {
        return $suppression->reason !== MessageSuppression::REASON_COMPLAINT;
    }

    public function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            MessageSuppression::REASON_BOUNCE => 'Bounced',
            MessageSuppression::REASON_COMPLAINT => 'Complaint',
            MessageSuppression::REASON_MANUAL => 'Manually suppressed',
            MessageSuppression::REASON_PROVIDER => 'Provider suppression',
            MessageSuppression::REASON_INVALID_DESTINATION => 'Invalid destination',
            MessageSuppression::REASON_REPEATED_FAILURE => 'Repeated delivery failure',
            default => 'Delivery issue',
        };
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