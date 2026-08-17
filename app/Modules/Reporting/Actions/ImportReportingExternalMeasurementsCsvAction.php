<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Services\ExternalMeasurements\MetaAdsCsvParser;
use Illuminate\Support\Facades\DB;

final class ImportReportingExternalMeasurementsCsvAction
{
    public function __construct(
        private readonly MetaAdsCsvParser $parser,
        private readonly UpsertReportingExternalMeasurementAction $upsert,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(
        string $path,
        ?string $accountId = null,
        ?string $accountTimezone = null,
    ): array {
        $fileHash = hash_file('sha256', $path);
        $parsed = $this->parser->parse(
            path: $path,
            accountId: $accountId,
            accountTimezone: $accountTimezone,
            sourceFileHash: is_string($fileHash) ? $fileHash : null,
        );

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($parsed, &$created, &$updated): void {
            foreach ($parsed['measurements'] as $measurement) {
                $stored = $this->upsert->handle($measurement);

                if ($stored->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }
        });

        return [
            ...$parsed,
            'created_count' => $created,
            'updated_count' => $updated,
        ];
    }
}