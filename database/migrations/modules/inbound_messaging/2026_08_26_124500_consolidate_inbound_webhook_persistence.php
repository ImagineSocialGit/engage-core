<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhook_inbox_receipts')) {
            throw new \RuntimeException(
                'Cannot consolidate inbound webhook persistence before the canonical webhook inbox is installed.',
            );
        }

        $this->assertNoDuplicateIdentity('provider_event_id', 'event');
        $this->assertNoDuplicateIdentity('provider_message_id', 'message');

        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->foreignId('webhook_inbox_receipt_id')
                ->nullable()
                ->after('id')
                ->constrained('webhook_inbox_receipts')
                ->nullOnDelete();

            $table->char('provider_event_key', 64)
                ->nullable()
                ->after('provider_event_id');
            $table->char('provider_message_key', 64)
                ->nullable()
                ->after('provider_message_id');
        });

        $this->backfillProviderIdentityKeys();
        $this->backfillWebhookReceiptReferences();

        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->unique(
                'provider_event_key',
                'inbound_messages_provider_event_key_unique',
            );
            $table->unique(
                'provider_message_key',
                'inbound_messages_provider_message_key_unique',
            );
        });

        if (Schema::hasColumn('inbound_messages', 'meta')) {
            Schema::table('inbound_messages', function (Blueprint $table): void {
                $table->dropColumn('meta');
            });
        }

        Schema::dropIfExists('inbound_message_receipts');
    }

    public function down(): void
    {
        if (! Schema::hasTable('inbound_message_receipts')) {
            Schema::create('inbound_message_receipts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('inbound_message_id')
                    ->nullable()
                    ->unique()
                    ->constrained('inbound_messages')
                    ->cascadeOnDelete();
                $table->string('client_key')->nullable()->index();
                $table->string('provider')->index();
                $table->string('provider_event_id')->nullable()->index();
                $table->string('provider_message_id')->nullable()->index();
                $table->char('provider_event_key', 64)
                    ->nullable()
                    ->unique('inbound_receipts_provider_event_key_unique');
                $table->char('provider_message_key', 64)
                    ->nullable()
                    ->unique('inbound_receipts_provider_message_key_unique');
                $table->string('status')->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('response_message')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('last_attempted_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('inbound_messages', 'meta')) {
            Schema::table('inbound_messages', function (Blueprint $table): void {
                $table->json('meta')->nullable();
            });
        }

        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->dropUnique('inbound_messages_provider_event_key_unique');
            $table->dropUnique('inbound_messages_provider_message_key_unique');
            $table->dropConstrainedForeignId('webhook_inbox_receipt_id');
            $table->dropColumn([
                'provider_event_key',
                'provider_message_key',
            ]);
        });
    }

    private function assertNoDuplicateIdentity(
        string $column,
        string $label,
    ): void {
        $duplicate = DB::table('inbound_messages')
            ->selectRaw("TRIM(COALESCE(client_key, '')) AS normalized_client_key")
            ->selectRaw('LOWER(TRIM(provider)) AS normalized_provider')
            ->selectRaw("TRIM({$column}) AS normalized_identifier")
            ->selectRaw('COUNT(*) AS aggregate')
            ->whereNotNull($column)
            ->whereRaw("TRIM({$column}) <> ''")
            ->groupByRaw("TRIM(COALESCE(client_key, '')), LOWER(TRIM(provider)), TRIM({$column})")
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new \RuntimeException(
                "Cannot consolidate inbound webhook persistence: duplicate normalized provider {$label} identity exists.",
            );
        }
    }

    private function backfillProviderIdentityKeys(): void
    {
        DB::table('inbound_messages')
            ->select([
                'id',
                'client_key',
                'provider',
                'provider_event_id',
                'provider_message_id',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($messages): void {
                foreach ($messages as $message) {
                    $clientKey = $this->nullableString($message->client_key ?? null);
                    $provider = strtolower(trim((string) ($message->provider ?? '')));
                    $providerEventId = $this->nullableString(
                        $message->provider_event_id ?? null,
                    );
                    $providerMessageId = $this->nullableString(
                        $message->provider_message_id ?? null,
                    );

                    DB::table('inbound_messages')
                        ->where('id', $message->id)
                        ->update([
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
                        ]);
                }
            }, 'id');
    }

    private function backfillWebhookReceiptReferences(): void
    {
        DB::statement(<<<'SQL'
UPDATE inbound_messages AS inbound
INNER JOIN webhook_inbox_receipts AS receipt
    ON COALESCE(receipt.client_key, '') = TRIM(COALESCE(inbound.client_key, ''))
    AND receipt.provider = LOWER(TRIM(inbound.provider))
    AND receipt.provider_event_id = CASE
        WHEN NULLIF(TRIM(inbound.provider_event_id), '') IS NOT NULL
            THEN TRIM(inbound.provider_event_id)
        ELSE NULLIF(TRIM(inbound.provider_message_id), '')
    END
SET inbound.webhook_inbox_receipt_id = receipt.id
WHERE inbound.webhook_inbox_receipt_id IS NULL
SQL);
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
};