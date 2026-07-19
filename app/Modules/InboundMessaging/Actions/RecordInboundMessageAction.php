<?php

namespace App\Modules\InboundMessaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Models\InboundMessageReceipt;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordInboundMessageAction
{
    public const NORMAL_REPLY_AUTOMATION_EVENT_KEY = 'inbound_message.normal_reply';

    public function __construct(
        private readonly AutomationEventOutbox $automationEventOutbox,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function handle(array $data, ?Model $sender = null): InboundMessage
    {
        $identity = $this->identity($data);

        try {
            return DB::transaction(
                fn (): InboundMessage => $this->record($data, $sender, $identity),
                3,
            );
        } catch (UniqueConstraintViolationException $exception) {
            $receipt = $this->receiptForIdentity($identity);
            $inboundMessage = $receipt?->inboundMessage()->first();

            if ($inboundMessage instanceof InboundMessage) {
                return $inboundMessage;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array{
     *     client_key: ?string,
     *     provider: string,
     *     provider_event_id: ?string,
     *     provider_message_id: ?string,
     *     provider_event_key: ?string,
     *     provider_message_key: ?string
     * } $identity
     */
    private function record(
        array $data,
        ?Model $sender,
        array $identity,
    ): InboundMessage {
        $receipt = $this->receiptForIdentity($identity, lock: true);

        if ($receipt instanceof InboundMessageReceipt) {
            return $this->messageForReceipt($receipt);
        }

        $legacyMessage = $this->legacyMessage($identity);

        if ($legacyMessage instanceof InboundMessage) {
            $this->createReceipt(
                identity: $identity,
                status: InboundMessageReceipt::STATUS_COMPLETED,
                inboundMessage: $legacyMessage,
                completedAt: now(),
            );

            return $legacyMessage;
        }

        $receipt = $this->createReceipt(
            identity: $identity,
            status: InboundMessageReceipt::STATUS_RECEIVED,
        );

        $inboundMessage = new InboundMessage([
            'client_key' => $identity['client_key'],
            'channel' => $data['channel'],
            'provider' => $identity['provider'],
            'provider_event_id' => $identity['provider_event_id'],
            'provider_message_id' => $identity['provider_message_id'],
            'provider_context_id' => $data['provider_context_id'] ?? null,
            'from_type' => $data['from_type'] ?? null,
            'from_value' => $data['from_value'] ?? null,
            'to_type' => $data['to_type'] ?? null,
            'to_value' => $data['to_value'] ?? null,
            'body' => $data['body'] ?? null,
            'classification' => $data['classification'],
            'purpose' => $data['purpose'] ?? null,
            'scope' => $data['scope'] ?? null,
            'received_at' => $data['received_at'] ?? null,
            'processed_at' => $data['processed_at'] ?? null,
            'meta' => $data['meta'] ?? null,
        ]);

        if ($sender) {
            $inboundMessage->sender()->associate($sender);
        }

        $inboundMessage->save();

        $receipt->forceFill([
            'inbound_message_id' => $inboundMessage->getKey(),
        ])->save();

        $this->recordNormalReplyAutomationEvent(
            inboundMessage: $inboundMessage,
            sender: $sender,
        );

        return $inboundMessage;
    }

    /**
     * @param array{
     *     client_key: ?string,
     *     provider: string,
     *     provider_event_id: ?string,
     *     provider_message_id: ?string,
     *     provider_event_key: ?string,
     *     provider_message_key: ?string
     * } $identity
     */
    private function receiptForIdentity(
        array $identity,
        bool $lock = false,
    ): ?InboundMessageReceipt {
        if ($identity['provider_event_key'] === null
            && $identity['provider_message_key'] === null
        ) {
            return null;
        }

        $query = InboundMessageReceipt::query()
            ->where(function (Builder $identities) use ($identity): void {
                if ($identity['provider_event_key'] !== null) {
                    $identities->where(
                        'provider_event_key',
                        $identity['provider_event_key'],
                    );
                }

                if ($identity['provider_message_key'] !== null) {
                    $method = $identity['provider_event_key'] !== null
                        ? 'orWhere'
                        : 'where';

                    $identities->{$method}(
                        'provider_message_key',
                        $identity['provider_message_key'],
                    );
                }
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $receipts = $query->limit(2)->get();

        if ($receipts->count() > 1) {
            throw new LogicException(
                'Inbound provider event and message identifiers resolve to different receipts.',
            );
        }

        return $receipts->first();
    }

    /**
     * @param array{
     *     client_key: ?string,
     *     provider: string,
     *     provider_event_id: ?string,
     *     provider_message_id: ?string,
     *     provider_event_key: ?string,
     *     provider_message_key: ?string
     * } $identity
     */
    private function legacyMessage(array $identity): ?InboundMessage
    {
        if ($identity['provider_event_id'] === null
            && $identity['provider_message_id'] === null
        ) {
            return null;
        }

        return InboundMessage::query()
            ->where('client_key', $identity['client_key'])
            ->where('provider', $identity['provider'])
            ->where(function (Builder $identifiers) use ($identity): void {
                if ($identity['provider_event_id'] !== null) {
                    $identifiers->where(
                        'provider_event_id',
                        $identity['provider_event_id'],
                    );
                }

                if ($identity['provider_message_id'] !== null) {
                    $method = $identity['provider_event_id'] !== null
                        ? 'orWhere'
                        : 'where';

                    $identifiers->{$method}(
                        'provider_message_id',
                        $identity['provider_message_id'],
                    );
                }
            })
            ->lockForUpdate()
            ->orderBy('id')
            ->first();
    }

    /**
     * @param array{
     *     client_key: ?string,
     *     provider: string,
     *     provider_event_id: ?string,
     *     provider_message_id: ?string,
     *     provider_event_key: ?string,
     *     provider_message_key: ?string
     * } $identity
     */
    private function createReceipt(
        array $identity,
        string $status,
        ?InboundMessage $inboundMessage = null,
        mixed $completedAt = null,
    ): InboundMessageReceipt {
        return InboundMessageReceipt::query()->create([
            'inbound_message_id' => $inboundMessage?->getKey(),
            ...$identity,
            'status' => $status,
            'attempts' => 0,
            'completed_at' => $completedAt,
        ]);
    }

    private function messageForReceipt(
        InboundMessageReceipt $receipt,
    ): InboundMessage {
        $inboundMessage = $receipt->inboundMessage()->first();

        if (! $inboundMessage instanceof InboundMessage) {
            throw new LogicException(
                "Inbound message receipt [{$receipt->getKey()}] has no message.",
            );
        }

        return $inboundMessage;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     client_key: ?string,
     *     provider: string,
     *     provider_event_id: ?string,
     *     provider_message_id: ?string,
     *     provider_event_key: ?string,
     *     provider_message_key: ?string
     * }
     */
    private function identity(array $data): array
    {
        $clientKey = $this->nullableString(
            $data['client_key'] ?? config('client.key'),
        );
        $provider = strtolower($this->requiredString(
            $data['provider'] ?? null,
            'provider',
        ));
        $providerEventId = $this->nullableString(
            $data['provider_event_id'] ?? null,
        );
        $providerMessageId = $this->nullableString(
            $data['provider_message_id'] ?? null,
        );

        return [
            'client_key' => $clientKey,
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'provider_message_id' => $providerMessageId,
            'provider_event_key' => $this->providerKey(
                clientKey: $clientKey,
                provider: $provider,
                identifierType: 'event',
                identifier: $providerEventId,
            ),
            'provider_message_key' => $this->providerKey(
                clientKey: $clientKey,
                provider: $provider,
                identifierType: 'message',
                identifier: $providerMessageId,
            ),
        ];
    }

    private function providerKey(
        ?string $clientKey,
        string $provider,
        string $identifierType,
        ?string $identifier,
    ): ?string {
        if ($identifier === null) {
            return null;
        }

        return hash('sha256', implode("\0", [
            $clientKey ?? '',
            $provider,
            $identifierType,
            $identifier,
        ]));
    }

    private function requiredString(mixed $value, string $field): string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            throw new LogicException("Inbound message [{$field}] is required.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function recordNormalReplyAutomationEvent(
        InboundMessage $inboundMessage,
        ?Model $sender,
    ): void {
        if (! $sender instanceof Contact
            || $inboundMessage->classification !== InboundMessage::CLASSIFICATION_NORMAL_REPLY
        ) {
            return;
        }

        $this->automationEventOutbox->record(
            event: AutomationEventData::forSubject(
                eventKey: self::NORMAL_REPLY_AUTOMATION_EVENT_KEY,
                subject: $inboundMessage,
                contactId: $sender->getKey(),
                occurredAt: $inboundMessage->received_at,
                payload: [
                    'inbound_message' => [
                        'id' => $inboundMessage->getKey(),
                        'channel' => $this->value($inboundMessage->channel),
                        'classification' => $inboundMessage->classification,
                        'purpose' => $this->value($inboundMessage->purpose),
                        'scope' => $inboundMessage->scope,
                        'received_at' => $inboundMessage->received_at?->toISOString(),
                    ],
                ],
                meta: [
                    'source_module' => 'inbound_messaging',
                    'source' => 'inbound_message_received',
                ],
            ),
            idempotencyKey: implode(':', [
                'inbound_messaging',
                self::NORMAL_REPLY_AUTOMATION_EVENT_KEY,
                $inboundMessage->getKey(),
            ]),
        );
    }

    private function value(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }
}