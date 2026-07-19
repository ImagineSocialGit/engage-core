<?php

namespace App\Modules\InboundMessaging\Actions\Sms;

use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Models\InboundMessageReceipt;
use App\Modules\InboundMessaging\Services\InboundMessageRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class ProcessInboundSmsMessageAction
{
    public function __construct(
        private readonly InboundMessageRouter $inboundMessageRouter,
    ) {}

    public function handle(InboundMessage $inboundMessage): ?string
    {
        $receiptId = $inboundMessage->receipt()->value('id');

        if (! is_numeric($receiptId)) {
            throw new LogicException(
                "Inbound message [{$inboundMessage->getKey()}] has no durable receipt.",
            );
        }

        try {
            return DB::transaction(
                fn (): ?string => $this->process(
                    receiptId: (int) $receiptId,
                    inboundMessageId: (int) $inboundMessage->getKey(),
                ),
                3,
            );
        } catch (Throwable $exception) {
            $this->markRetryableFailure((int) $receiptId, $exception);

            throw $exception;
        }
    }

    private function process(
        int $receiptId,
        int $inboundMessageId,
    ): ?string {
        $receipt = InboundMessageReceipt::query()
            ->lockForUpdate()
            ->findOrFail($receiptId);

        if ($receipt->status === InboundMessageReceipt::STATUS_COMPLETED) {
            return $receipt->response_message;
        }

        $inboundMessage = InboundMessage::query()
            ->lockForUpdate()
            ->findOrFail($inboundMessageId);

        $attemptedAt = now();

        $receipt->forceFill([
            'status' => InboundMessageReceipt::STATUS_PROCESSING,
            'attempts' => ((int) $receipt->attempts) + 1,
            'last_attempted_at' => $attemptedAt,
            'last_error' => null,
        ])->save();

        event(new InboundMessageReceived($inboundMessage));

        $responseMessage = $this->inboundMessageRouter->route($inboundMessage);

        $receipt->forceFill([
            'status' => InboundMessageReceipt::STATUS_COMPLETED,
            'response_message' => $responseMessage,
            'completed_at' => now(),
            'last_error' => null,
        ])->save();

        return $responseMessage;
    }

    private function markRetryableFailure(
        int $receiptId,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($receiptId, $exception): void {
            $receipt = InboundMessageReceipt::query()
                ->lockForUpdate()
                ->find($receiptId);

            if (! $receipt instanceof InboundMessageReceipt
                || $receipt->status === InboundMessageReceipt::STATUS_COMPLETED
            ) {
                return;
            }

            $receipt->forceFill([
                'status' => InboundMessageReceipt::STATUS_RETRYABLE_FAILED,
                'attempts' => ((int) $receipt->attempts) + 1,
                'last_attempted_at' => now(),
                'last_error' => Str::limit($exception->getMessage(), 65000, ''),
            ])->save();
        }, 3);
    }
}