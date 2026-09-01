<?php

namespace App\Modules\InboundMessaging\Actions\Email;

use App\Modules\Messaging\Actions\HandleEmailProviderEventAction;
use App\Modules\Messaging\Models\MessageSuppression;

/**
 * @deprecated Provider delivery/lifecycle consequences are Messaging-owned.
 *             Keep this adapter temporarily for existing callers/tests while
 *             provider configuration moves to the dedicated message-events endpoint.
 */
final class HandleInboundEmailWebhookAction
{
    public function __construct(
        private readonly HandleEmailProviderEventAction $providerEvents,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(
        array $event,
        ?string $sourceEventId = null,
        string $provider = MessageSuppression::PROVIDER_RESEND,
    ): void {
        $this->providerEvents->handle(
            event: $event,
            sourceEventId: $sourceEventId,
            provider: $provider,
        );
    }
}