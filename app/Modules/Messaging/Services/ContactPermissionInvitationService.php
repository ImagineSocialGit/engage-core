<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ContactPermissionInvitationService
{
    public function __construct(
        private readonly MessageChannelAvailability $channelAvailability,
    ) {}

    public const MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION = 'imported_contact_permission_invitation';

    /**
     * @param array<string, mixed> $context
     */
    public function allowsImportedContactInvitationPass(
        Contact $contact,
        string $channel,
        string $messageType,
        array $context,
    ): bool {
        if (! $this->isImportedContactPermissionInvitationPolicy($context)) {
            return false;
        }

        if ($channel !== MessageChannel::Email->value) {
            return false;
        }

        if ($messageType !== self::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION) {
            return false;
        }

        if (! $this->isImportedContact($contact)) {
            return false;
        }

        return ! $this->hasExistingImportedContactEmailInvitation($contact);
    }

    public function isImportedContactPermissionInvitationMessage(ScheduledMessage $scheduledMessage): bool
    {
        if ($scheduledMessage->channel !== MessageChannel::Email->value) {
            return false;
        }

        if ($scheduledMessage->message_type !== self::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION) {
            return false;
        }

        return $this->isImportedContactPermissionInvitationPolicy([
            'consent_policy' => $scheduledMessage->meta['consent_policy'] ?? [],
        ]);
    }

    public function claimForScheduledMessage(ScheduledMessage $scheduledMessage): ?ContactPermissionInvitation
    {
        if (! $this->isImportedContactPermissionInvitationMessage($scheduledMessage)) {
            return null;
        }

        $contact = $scheduledMessage->recipient;

        if (! $contact instanceof Contact) {
            return null;
        }

        if (! $this->isImportedContact($contact)) {
            return null;
        }

        try {
            return ContactPermissionInvitation::query()->create([
                'contact_id' => $contact->getKey(),
                'scheduled_message_id' => $scheduledMessage->getKey(),
                'token' => $this->newToken(),
                'context_type' => $scheduledMessage->context_type,
                'context_id' => $scheduledMessage->context_id,
                'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
                'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                'status' => ContactPermissionInvitation::STATUS_CLAIMED,
                'claimed_at' => now(),
                'meta' => [
                    'scheduled_message' => [
                        'message_type' => $scheduledMessage->message_type,
                        'purpose' => $scheduledMessage->purpose,
                        'scope' => $scheduledMessage->scope,
                    ],
                ],
            ]);
        } catch (QueryException) {
            return null;
        }
    }

    public function markSent(
        ContactPermissionInvitation $invitation,
        ScheduledMessage $scheduledMessage,
    ): void {
        $sentAt = $scheduledMessage->sent_at ?? now();

        $invitation->forceFill([
            'scheduled_message_id' => $scheduledMessage->getKey(),
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'sent_at' => $sentAt,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    public function markFailed(
        ContactPermissionInvitation $invitation,
        ScheduledMessage $scheduledMessage,
        string $reason,
    ): void {
        $invitation->forceFill([
            'scheduled_message_id' => $scheduledMessage->getKey(),
            'status' => ContactPermissionInvitation::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }

    /**
     * @param array<int, string> $channels
     */
    public function accept(
        ContactPermissionInvitation $invitation,
        array $channels,
        Request $request,
    ): ContactPermissionInvitation {
        $channels = $this->normalizedAcceptedChannels($channels);
        $contact = $invitation->contact;

        if (! $contact) {
            return $invitation;
        }

        foreach ($channels as $channel) {
            foreach ($this->consentScopes() as $scope) {
                MessageConsent::query()->updateOrCreate(
                    [
                        'contact_id' => $contact->getKey(),
                        'channel' => $channel,
                        'purpose' => MessagePurpose::Marketing->value,
                        'scope' => $scope,
                    ],
                    [
                        'consented_at' => now(),
                        'source' => 'imported_contact_permission_invitation',
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'meta' => [
                            'permission_invitation_id' => $invitation->getKey(),
                            'permission_invitation_source' => $invitation->source,
                            'accepted_from' => 'public_form',
                        ],
                    ],
                );
            }
        }

        $invitation->forceFill([
            'status' => ContactPermissionInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_channels' => $channels,
            'meta' => array_replace_recursive($invitation->meta ?? [], [
                'accepted' => [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'channels' => $channels,
                    'scopes' => $this->consentScopes(),
                ],
            ]),
        ])->save();

        return $invitation->refresh();
    }

    public function findPublicInvitation(string $token): ?ContactPermissionInvitation
    {
        return ContactPermissionInvitation::query()
            ->with('contact')
            ->where('token', $token)
            ->first();
    }

    public function publicUrl(ContactPermissionInvitation $invitation): string
    {
        return route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicEmailPayload(ContactPermissionInvitation $invitation): array
    {
        $url = $this->publicUrl($invitation);

        return [
            'tokens' => [
                'permission_invitation' => [
                    'url' => $url,
                ],
                'contact' => [
                    'first_name' => $invitation->contact?->first_name,
                    'last_name' => $invitation->contact?->last_name,
                    'name' => $invitation->contact?->name,
                    'email' => $invitation->contact?->email,
                ],
            ],
            'cta' => [
                'label' => config('messaging.permission_invitations.email.cta_label', 'Confirm my preferences'),
                'url' => $url,
            ],
            'secondary_link' => [
                'label' => config('messaging.permission_invitations.email.secondary_link_label', 'Or copy and paste this link into your browser'),
                'url' => $url,
            ],
        ];
    }

    public function hasExistingImportedContactEmailInvitation(Contact $contact): bool
    {
        return ContactPermissionInvitation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', ContactPermissionInvitation::CHANNEL_EMAIL)
            ->where('source', ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT)
            ->exists();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isImportedContactPermissionInvitationPolicy(array $context): bool
    {
        $policy = $context['consent_policy']['permission_invitation']
            ?? $context['permission_invitation']
            ?? [];

        if (! is_array($policy)) {
            return false;
        }

        return ($policy['source'] ?? null) === ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT
            && (bool) ($policy['one_time'] ?? false);
    }

    private function isImportedContact(Contact $contact): bool
    {
        $source = is_string($contact->source)
            ? str_replace('-', '_', strtolower(trim($contact->source)))
            : null;

        if ($source === 'import') {
            return true;
        }

        $meta = is_array($contact->meta) ? $contact->meta : [];

        return (bool) ($meta['imported'] ?? false)
            || array_key_exists('imported_at', $meta);
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(64);
        } while (ContactPermissionInvitation::query()->where('token', $token)->exists());

        return $token;
    }

    /**
     * @param array<int, string> $channels
     * @return array<int, string>
     */
    private function normalizedAcceptedChannels(array $channels): array
    {
        return $this->channelAvailability->normalizeVisibleChannelsForSurface(
            channels: $channels,
            surface: 'permission_invitations',
            purpose: 'marketing',
            scope: 'broadcast',
        );
    }

    /**
     * @return array<int, string>
     */
    private function consentScopes(): array
    {
        $scopes = config('messaging.permission_invitations.consent.scopes', [
            'broadcast',
            'campaign',
        ]);

        if (! is_array($scopes)) {
            return ['broadcast'];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $scope): ?string => is_string($scope) && trim($scope) !== ''
                ? str_replace('-', '_', strtolower(trim($scope)))
                : null,
            Arr::wrap($scopes),
        )))) ?: ['broadcast'];
    }
}