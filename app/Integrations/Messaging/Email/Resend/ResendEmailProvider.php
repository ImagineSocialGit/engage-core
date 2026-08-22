<?php

namespace App\Integrations\Messaging\Email\Resend;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Email\EmailProvider;
use App\Modules\Messaging\Contracts\Email\ThreadedEmailMessage;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Support\EmailReplyAddressGenerator;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

class ResendEmailProvider implements EmailProvider
{
    public function __construct(
        private readonly EmailReplyAddressGenerator $replyAddressGenerator,
    ) {}

    public function provider(): string
    {
        return 'resend';
    }

    public function send(
        EmailMessage $message,
        ?string $idempotencyKey = null,
    ): MessageSendResult {
        $mailable = $message->mailable();
        $scheduledMessageId = data_get($message, 'meta.delivery.scheduled_message_id');
        $replyTo = is_numeric($scheduledMessageId)
            ? $this->replyAddressGenerator->forScheduledMessageId((int) $scheduledMessageId)
            : null;

        if (is_string($replyTo) && $replyTo !== '') {
            $mailable->replyTo($replyTo);
        }

        $inReplyTo = $message instanceof ThreadedEmailMessage
            ? $message->inReplyTo()
            : null;
        $references = $message instanceof ThreadedEmailMessage
            ? $message->references()
            : null;

        if (filled($idempotencyKey)
            || filled($inReplyTo)
            || filled($references)
        ) {
            $mailable->withSymfonyMessage(
                static function (Email $email) use (
                    $idempotencyKey,
                    $inReplyTo,
                    $references,
                ): void {
                    if (filled($idempotencyKey)) {
                        $email->getHeaders()->addTextHeader(
                            'Resend-Idempotency-Key',
                            $idempotencyKey,
                        );
                    }

                    if (filled($inReplyTo)) {
                        $email->getHeaders()->addTextHeader(
                            'In-Reply-To',
                            $inReplyTo,
                        );
                    }

                    if (filled($references)) {
                        $email->getHeaders()->addTextHeader(
                            'References',
                            $references,
                        );
                    }
                },
            );
        }

        Mail::to($message->to())->send($mailable);

        return MessageSendResult::sent(provider: $this->provider());
    }
}