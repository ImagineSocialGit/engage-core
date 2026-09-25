<?php

namespace App\Support\TestingTools;

use App\Support\ProjectState\ProjectStateContractRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ProductionRuntimeSnapshotImporter
{
    private const SNAPSHOT_MODE = 'runtime_state_with_definition_replay_v2';

    private const MODE_IDENTITY_ONLY = 'identity_only';

    private const MODE_REPLAY_FULL = 'replay_full';

    /** @var list<string> */
    private const ENVIRONMENT_TABLES = [
        'module_installations',
        'users',
        'user_access_profiles',
    ];

    /**
     * Definition/configuration tables are never bulk-inserted as ordinary runtime rows.
     * identity_only rows map onto the freshly synced DEV definition. replay_full rows
     * are deliberately overlaid/recreated because production authored/customized or
     * runtime-pinned immutable definition state is required for faithful review.
     *
     * Parent tables must precede children.
     *
     * @var list<string>
     */
    private const DEFINITION_TABLES = [
        'business_calendars',
        'contact_statuses',
        'task_templates',
        'flow_route_capabilities',
        'message_template_presets',
        'message_templates',
        'message_template_versions',
        'message_template_preset_assignments',
        'message_template_catalog_entries',
        'message_chains',
        'message_chain_versions',
        'message_chain_steps',
        'message_chain_step_variants',
        'campaigns',
        'campaign_steps',
        'campaign_step_variants',
        'flow_routes',
        'flow_route_points',
        'flow_route_trigger_bindings',
        'inbound_reply_profiles',
        'inbound_reply_intents',
        'inbound_reply_rules',
        'webinar_schedule_profiles',
        'webinar_schedule_profile_items',
        'webinar_schedule_profile_chain_bindings',
    ];

    /** @var list<string> */
    private const REPLAY_BY_IDENTITY_TABLES = [
        'message_template_composition_layers',
    ];

    /** @var array<string, array<int|string, int|string>> */
    private array $idMaps = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, array<string, string>> */
    private array $foreignKeys = [];

    private ?int $localUserId = null;

    /** @var array<string, array<string, mixed>> */
    private array $projectStateDefinitions = [];

    /** @var array<int|string, int|string|null> */
    private array $deferredTemplateCurrentVersions = [];

    /** @var array<int|string, int|string|null> */
    private array $deferredChainCurrentVersions = [];

    /** @var array<int|string, int|string|null> */
    private array $deferredFlowPointNextIds = [];

    public function __construct(
        private readonly ProjectStateContractRegistry $projectStateContracts,
    ) {}

    /**
     * @return array{
     *     source_database: string|null,
     *     source_generated_at: string|null,
     *     source_client_key: string|null,
     *     tables_seen: int,
     *     runtime_tables: int,
     *     runtime_rows: int,
     *     mapped_reference_rows: int,
     *     replay_rows: int,
     *     isolated_message_chain_enrollments: int,
     *     held_scheduled_messages: int,
     *     warnings: list<string>,
     *     imported_counts: array<string, int>,
     *     dry_run: bool
     * }
     */
    public function import(
        string $path,
        ?string $localUserEmail = null,
        bool $dryRun = false,
    ): array {
        $this->assertAvailableEnvironment();

        $snapshot = $this->readSnapshot($path);
        $this->assertSnapshot($snapshot);
        $this->assertTargetSchema($snapshot['schemas']);
        $sourceClientKey = $this->assertClientMatchesSnapshot($snapshot['rows']);

        $this->idMaps = [];
        $this->warnings = [];
        $this->deferredTemplateCurrentVersions = [];
        $this->deferredChainCurrentVersions = [];
        $this->deferredFlowPointNextIds = [];
        $this->foreignKeys = $this->foreignKeysForTables(array_keys($snapshot['schemas']));
        $this->projectStateDefinitions = $this->projectStateDefinitions();
        $this->localUserId = $this->resolveLocalUserId(
            $snapshot['rows']['users'] ?? [],
            $localUserEmail,
        );

        $environmentMappings = $this->mapEnvironmentRows($snapshot['rows']);
        $definitionPlan = $this->planDefinitionMappings($snapshot['rows']);
        $this->assertReplayDefinitionTreesAreComplete($snapshot['rows']);

        $runtimeTables = $this->runtimeTables($snapshot['table_order'], $snapshot['rows']);
        $this->assertRuntimeTablesAreEmpty($runtimeTables, $snapshot['rows']);

        $runtimeRows = array_sum(array_map(
            fn (string $table): int => count($snapshot['rows'][$table] ?? []),
            $runtimeTables,
        ));

        $compositionReplayRows = count(
            $snapshot['rows']['message_template_composition_layers'] ?? [],
        );

        $summary = [
            'source_database' => $snapshot['meta']['database'] ?? null,
            'source_generated_at' => $snapshot['meta']['generated_at'] ?? null,
            'source_client_key' => $sourceClientKey,
            'tables_seen' => count($snapshot['schemas']),
            'runtime_tables' => count($runtimeTables),
            'runtime_rows' => $runtimeRows,
            'mapped_reference_rows' => $environmentMappings + $definitionPlan['mapped'],
            'replay_rows' => $definitionPlan['replay'] + $compositionReplayRows,
            'isolated_message_chain_enrollments' => 0,
            'held_scheduled_messages' => 0,
            'warnings' => [],
            'imported_counts' => [],
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            $summary['warnings'] = $this->warnings;

            return $summary;
        }

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use (&$summary, $snapshot, $runtimeTables): void {
                $this->replayDefinitionRows($snapshot['rows']);
                $this->applyDeferredDefinitionReferences();

                $this->replayIdentityRows(
                    table: 'message_template_composition_layers',
                    rows: $snapshot['rows']['message_template_composition_layers'] ?? [],
                );

                foreach ($runtimeTables as $table) {
                    $rows = $snapshot['rows'][$table] ?? [];

                    if ($rows === []) {
                        continue;
                    }

                    $prepared = [];

                    foreach ($rows as $sourceRow) {
                        $row = $this->remapRowReferences($table, $sourceRow['data']);
                        $row = $this->makeRuntimeRowInert($table, $row, $summary);
                        $prepared[] = $row;
                    }

                    foreach (array_chunk($prepared, 250) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }

                    $summary['imported_counts'][$table] = count($prepared);
                }
            }, 1);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $summary['warnings'] = $this->warnings;

        return $summary;
    }

    private function assertAvailableEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Production runtime snapshots may be imported only in local or testing environments.',
            );
        }
    }

    /**
     * @return array{
     *     meta: array<string, mixed>,
     *     schemas: array<string, array<int, array<string, mixed>>>,
     *     rows: array<string, list<array{mode: string, data: array<string, mixed>}>>,
     *     table_order: list<string>
     * }
     */
    private function readSnapshot(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Snapshot file [{$path}] is not readable.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Snapshot file [{$path}] could not be opened.");
        }

        $meta = [];
        $schemas = [];
        $rows = [];
        $tableOrder = [];
        $lineNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                try {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        "Snapshot JSONL line {$lineNumber} is invalid: {$exception->getMessage()}",
                        previous: $exception,
                    );
                }

                if (! is_array($record)) {
                    throw new RuntimeException("Snapshot JSONL line {$lineNumber} is not an object.");
                }

                $type = $record['type'] ?? null;

                if ($type === 'dump_meta') {
                    $meta = $record;
                    continue;
                }

                if ($type === 'table_schema') {
                    $table = $this->requiredTableName($record['table'] ?? null, $lineNumber);
                    $schemas[$table] = is_array($record['columns'] ?? null)
                        ? $record['columns']
                        : [];
                    $tableOrder[] = $table;
                    continue;
                }

                if ($type === 'row') {
                    $table = $this->requiredTableName($record['table'] ?? null, $lineNumber);
                    $data = $record['data'] ?? null;

                    if (! is_array($data)) {
                        throw new RuntimeException("Snapshot row on line {$lineNumber} has no data object.");
                    }

                    $rows[$table][] = [
                        'mode' => is_string($record['mode'] ?? null)
                            ? $record['mode']
                            : 'full',
                        'data' => $data,
                    ];
                    continue;
                }

                if ($type === 'table_error') {
                    $table = is_string($record['table'] ?? null) ? $record['table'] : 'unknown';
                    $error = is_string($record['error'] ?? null) ? $record['error'] : 'unknown error';
                    throw new RuntimeException("Source snapshot contains table error for [{$table}]: {$error}");
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'meta' => $meta,
            'schemas' => $schemas,
            'rows' => $rows,
            'table_order' => array_values(array_unique($tableOrder)),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function assertSnapshot(array $snapshot): void
    {
        $meta = $snapshot['meta'] ?? [];

        if (($meta['mode'] ?? null) !== self::SNAPSHOT_MODE) {
            throw new RuntimeException(
                'Snapshot is not a definition-replay v2 runtime snapshot. Recreate the production dump with the Batch 3B capture command; the earlier identity-only dump cannot faithfully reconstruct production-authored message/template history.',
            );
        }

        if (($snapshot['schemas'] ?? []) === []) {
            throw new RuntimeException('Snapshot does not contain any table schemas.');
        }
    }

    /** @param array<string, array<int, array<string, mixed>>> $schemas */
    private function assertTargetSchema(array $schemas): void
    {
        $missingTables = [];
        $missingColumns = [];

        foreach ($schemas as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;
                continue;
            }

            $targetColumns = array_flip(Schema::getColumnListing($table));

            foreach ($columns as $column) {
                $name = $column['Field'] ?? null;

                if (is_string($name) && ! isset($targetColumns[$name])) {
                    $missingColumns[] = "{$table}.{$name}";
                }
            }
        }

        if ($missingTables !== [] || $missingColumns !== []) {
            $parts = [];

            if ($missingTables !== []) {
                $parts[] = 'missing tables: '.implode(', ', $missingTables);
            }

            if ($missingColumns !== []) {
                $parts[] = 'missing columns: '.implode(', ', array_slice($missingColumns, 0, 30));
            }

            throw new RuntimeException(
                'Target schema is not compatible with this snapshot ('.implode('; ', $parts).').',
            );
        }
    }

    /**
     * @param array<string, list<array{mode: string, data: array<string, mixed>}>> $rowsByTable
     */
    private function assertClientMatchesSnapshot(array $rowsByTable): ?string
    {
        $rawKeys = [];
        $canonicalKeys = [];

        foreach ($rowsByTable as $rows) {
            foreach ($rows as $row) {
                $clientKey = $row['data']['client_key'] ?? null;

                if (! is_string($clientKey) || trim($clientKey) === '') {
                    continue;
                }

                $rawKey = trim($clientKey);
                $canonicalKey = str_replace(
                    '_',
                    '-',
                    mb_strtolower($rawKey),
                );

                $rawKeys[$rawKey] = true;
                $canonicalKeys[$canonicalKey] = true;
            }
        }

        $rawKeys = array_keys($rawKeys);
        $canonicalKeys = array_keys($canonicalKeys);

        if (count($canonicalKeys) > 1) {
            throw new RuntimeException(
                'Snapshot contains more than one client_key: '.implode(', ', $rawKeys).'.',
            );
        }

        if ($canonicalKeys === []) {
            return null;
        }

        $sourceClientKey = $canonicalKeys[0];
        $targetClientKey = trim((string) config('client.key', ''));
        $canonicalTargetClientKey = str_replace(
            '_',
            '-',
            mb_strtolower($targetClientKey),
        );

        if ($canonicalTargetClientKey !== $sourceClientKey) {
            throw new RuntimeException(
                "Snapshot belongs to client [{$sourceClientKey}] but this DEV runtime is configured for [".
                ($targetClientKey !== '' ? $targetClientKey : 'none').'].',
            );
        }

        return $targetClientKey;
    }

    /**
     * @param list<array{mode: string, data: array<string, mixed>}> $sourceUsers
     */
    private function resolveLocalUserId(array $sourceUsers, ?string $localUserEmail): ?int
    {
        if ($sourceUsers === []) {
            return null;
        }

        if (is_string($localUserEmail) && trim($localUserEmail) !== '') {
            $id = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($localUserEmail))])
                ->value('id');

            if ($id === null) {
                throw new RuntimeException(
                    "Local user [{$localUserEmail}] does not exist. Create it first or choose another --user-email.",
                );
            }

            return (int) $id;
        }

        foreach ($sourceUsers as $sourceUser) {
            $email = $sourceUser['data']['email'] ?? null;

            if (! is_string($email) || trim($email) === '') {
                continue;
            }

            $id = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
                ->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        $ids = DB::table('users')->orderBy('id')->pluck('id');

        if ($ids->count() === 1) {
            return (int) $ids->first();
        }

        throw new RuntimeException(
            'The snapshot contains production user references. Supply --user-email=<local CRM user> so those references can be mapped without importing production credentials.',
        );
    }

    /**
     * @param array<string, list<array{mode: string, data: array<string, mixed>}>> $rowsByTable
     */
    private function mapEnvironmentRows(array $rowsByTable): int
    {
        $mapped = 0;

        foreach ($rowsByTable['module_installations'] ?? [] as $row) {
            $moduleKey = $row['data']['module_key'] ?? null;

            if (! is_string($moduleKey) || trim($moduleKey) === '') {
                throw new RuntimeException('Production module_installations row is missing module_key.');
            }

            $moduleKey = trim($moduleKey);

            $installed = DB::table('module_installations')
                ->where('module_key', $moduleKey)
                ->exists();

            if (! $installed) {
                throw new RuntimeException(
                    "Installed production module [{$moduleKey}] is not installed in the target DEV database.",
                );
            }

            $mapped++;
        }

        $sourceUsers = $rowsByTable['users'] ?? [];

        if ($sourceUsers !== []) {
            if ($this->localUserId === null) {
                throw new RuntimeException('A local user mapping is required for the production user rows.');
            }

            foreach ($sourceUsers as $row) {
                $sourceId = $row['data']['id'] ?? null;

                if ($sourceId === null) {
                    continue;
                }

                $this->idMaps['users'][$sourceId] = $this->localUserId;
                $mapped++;
            }
        }

        $accessRows = $rowsByTable['user_access_profiles'] ?? [];

        if ($accessRows !== []) {
            $this->warnings[] = sprintf(
                'Skipped %d production user_access_profiles row(s); local authentication/access ownership is preserved.',
                count($accessRows),
            );
        }

        return $mapped;
    }

    /**
     * @param array<string, list<array{mode: string, data: array<string, mixed>}>> $rowsByTable
     * @return array{mapped: int, replay: int}
     */
    private function planDefinitionMappings(array $rowsByTable): array
    {
        $mapped = 0;
        $replay = 0;

        foreach (self::DEFINITION_TABLES as $table) {
            foreach ($rowsByTable[$table] ?? [] as $sourceRow) {
                $mode = $sourceRow['mode'];
                $row = $sourceRow['data'];
                $sourceId = $row['id'] ?? null;

                if ($sourceId === null) {
                    throw new RuntimeException("Definition row [{$table}] is missing id.");
                }

                if ($mode === self::MODE_IDENTITY_ONLY) {
                    $targetId = $this->resolveReferenceTargetId($table, $row);
                    $this->idMaps[$table][$sourceId] = $targetId;
                    $mapped++;
                    continue;
                }

                if ($mode !== self::MODE_REPLAY_FULL) {
                    throw new RuntimeException(
                        "Definition row [{$table}:{$sourceId}] has unsupported snapshot mode [{$mode}]. Recreate the production snapshot with the Batch 3B capture command.",
                    );
                }

                $this->assertReplayRowHasRequiredContent($table, $row);

                $existingId = $this->isDefinitionRootTable($table)
                    ? $this->findExistingDefinitionId($table, $this->preparedIdentityRow($table, $row))
                    : null;

                $this->idMaps[$table][$sourceId] = $existingId ?? (int) $sourceId;
                $replay++;
            }
        }

        return ['mapped' => $mapped, 'replay' => $replay];
    }

    /** @param array<string, mixed> $row */
    private function assertReplayRowHasRequiredContent(string $table, array $row): void
    {
        $required = match ($table) {
            'message_template_versions' => [
                'message_template_id', 'version', 'content', 'renderer_key',
                'renderer_version', 'content_hash',
            ],
            'message_chain_versions' => [
                'message_chain_id', 'version', 'content_hash',
            ],
            'message_chain_steps' => [
                'message_chain_version_id', 'key', 'timing_type', 'variant_strategy',
            ],
            'message_chain_step_variants' => [
                'message_chain_step_id', 'key', 'message_template_version_id',
                'channel', 'purpose', 'scope', 'message_type',
            ],
            'message_templates', 'message_chains' => ['key', 'name', 'status'],
            'message_template_presets' => ['key', 'name', 'channel', 'purpose', 'scope', 'payload'],
            default => [],
        };

        foreach ($required as $column) {
            if (! array_key_exists($column, $row)) {
                throw new RuntimeException(
                    "Replay definition row [{$table}:".(string) ($row['id'] ?? '?')."] is missing [{$column}]. The snapshot was reduced too aggressively and must be recreated.",
                );
            }
        }
    }

    /**
     * @param array<string, list<array{mode: string, data: array<string, mixed>}>> $rowsByTable
     */
    private function assertReplayDefinitionTreesAreComplete(array $rowsByTable): void
    {
        $replayIds = [];

        foreach (self::DEFINITION_TABLES as $table) {
            foreach ($rowsByTable[$table] ?? [] as $row) {
                if ($row['mode'] !== self::MODE_REPLAY_FULL) {
                    continue;
                }

                $id = $row['data']['id'] ?? null;

                if ($id !== null) {
                    $replayIds[$table][(string) $id] = true;
                }
            }
        }

        foreach ($rowsByTable['message_templates'] ?? [] as $row) {
            if ($row['mode'] !== self::MODE_REPLAY_FULL) {
                continue;
            }

            $currentVersionId = $row['data']['current_version_id'] ?? null;

            if ($currentVersionId !== null
                && ! isset($replayIds['message_template_versions'][(string) $currentVersionId])
            ) {
                throw new RuntimeException(
                    'Replayable production MessageTemplate ['.(string) ($row['data']['key'] ?? $row['data']['id'] ?? '?').'] does not include its current immutable MessageTemplateVersion. Recreate the production snapshot with the Batch 3B capture command.',
                );
            }
        }

        foreach ($rowsByTable['message_chains'] ?? [] as $row) {
            if ($row['mode'] !== self::MODE_REPLAY_FULL) {
                continue;
            }

            $currentVersionId = $row['data']['current_version_id'] ?? null;

            if ($currentVersionId !== null
                && ! isset($replayIds['message_chain_versions'][(string) $currentVersionId])
            ) {
                throw new RuntimeException(
                    'Replayable production MessageChain ['.(string) ($row['data']['key'] ?? $row['data']['id'] ?? '?').'] does not include its current immutable MessageChainVersion. Recreate the production snapshot with the Batch 3B capture command.',
                );
            }
        }

        $replayVersionIds = $replayIds['message_chain_versions'] ?? [];

        foreach ($rowsByTable['message_chain_steps'] ?? [] as $row) {
            $parentId = $row['data']['message_chain_version_id'] ?? null;

            if ($parentId !== null
                && isset($replayVersionIds[(string) $parentId])
                && $row['mode'] !== self::MODE_REPLAY_FULL
            ) {
                throw new RuntimeException(
                    'A replayable MessageChainVersion contains an identity-only MessageChainStep. Recreate the production snapshot with the Batch 3B capture command.',
                );
            }
        }

        $replayStepIds = $replayIds['message_chain_steps'] ?? [];

        foreach ($rowsByTable['message_chain_step_variants'] ?? [] as $row) {
            $parentId = $row['data']['message_chain_step_id'] ?? null;

            if ($parentId !== null
                && isset($replayStepIds[(string) $parentId])
                && $row['mode'] !== self::MODE_REPLAY_FULL
            ) {
                throw new RuntimeException(
                    'A replayable MessageChainStep contains an identity-only MessageChainStepVariant. Recreate the production snapshot with the Batch 3B capture command.',
                );
            }
        }
    }

    /**
     * @param array<string, list<array{mode: string, data: array<string, mixed>}>> $rowsByTable
     */
    private function replayDefinitionRows(array $rowsByTable): void
    {
        foreach (self::DEFINITION_TABLES as $table) {
            foreach ($rowsByTable[$table] ?? [] as $sourceRow) {
                if ($sourceRow['mode'] !== self::MODE_REPLAY_FULL) {
                    continue;
                }

                $this->replayDefinitionRow($table, $sourceRow['data']);
            }
        }
    }

    /** @param array<string, mixed> $sourceRow */
    private function replayDefinitionRow(string $table, array $sourceRow): void
    {
        $sourceId = $sourceRow['id'] ?? null;

        if ($sourceId === null) {
            throw new RuntimeException("Replay definition row [{$table}] is missing id.");
        }

        $row = $sourceRow;

        if ($table === 'message_templates') {
            $this->deferredTemplateCurrentVersions[$sourceId] = $row['current_version_id'] ?? null;
            $row['current_version_id'] = null;
        }

        if ($table === 'message_chains') {
            $this->deferredChainCurrentVersions[$sourceId] = $row['current_version_id'] ?? null;
            $row['current_version_id'] = null;
        }

        if ($table === 'flow_route_points') {
            $this->deferredFlowPointNextIds[$sourceId] = $row['next_flow_route_point_id'] ?? null;
            $row['next_flow_route_point_id'] = null;
        }

        $row = $this->remapRowReferences($table, $row);
        $existingId = $this->findExistingDefinitionId(
            $table,
            $this->preparedIdentityRow($table, $row),
        );

        unset($row['id']);

        if ($existingId !== null) {
            DB::table($table)->where('id', $existingId)->update($row);
            $targetId = (int) $existingId;
        } elseif (! DB::table($table)->where('id', $sourceId)->exists()) {
            DB::table($table)->insert(['id' => $sourceId] + $row);
            $targetId = (int) $sourceId;
        } else {
            $targetId = (int) DB::table($table)->insertGetId($row);
        }

        $this->idMaps[$table][$sourceId] = $targetId;
    }

    private function applyDeferredDefinitionReferences(): void
    {
        foreach ($this->deferredTemplateCurrentVersions as $sourceTemplateId => $sourceVersionId) {
            if ($sourceVersionId === null) {
                continue;
            }

            $targetTemplateId = $this->mappedId('message_templates', $sourceTemplateId);
            $targetVersionId = $this->mappedId('message_template_versions', $sourceVersionId);

            DB::table('message_templates')
                ->where('id', $targetTemplateId)
                ->update(['current_version_id' => $targetVersionId]);
        }

        foreach ($this->deferredChainCurrentVersions as $sourceChainId => $sourceVersionId) {
            if ($sourceVersionId === null) {
                continue;
            }

            $targetChainId = $this->mappedId('message_chains', $sourceChainId);
            $targetVersionId = $this->mappedId('message_chain_versions', $sourceVersionId);

            DB::table('message_chains')
                ->where('id', $targetChainId)
                ->update(['current_version_id' => $targetVersionId]);
        }

        foreach ($this->deferredFlowPointNextIds as $sourcePointId => $sourceNextPointId) {
            if ($sourceNextPointId === null) {
                continue;
            }

            $targetPointId = $this->mappedId('flow_route_points', $sourcePointId);
            $targetNextPointId = $this->mappedId('flow_route_points', $sourceNextPointId);

            DB::table('flow_route_points')
                ->where('id', $targetPointId)
                ->update(['next_flow_route_point_id' => $targetNextPointId]);
        }
    }

    /** @param array<string, mixed> $row */
    private function preparedIdentityRow(string $table, array $row): array
    {
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function findExistingDefinitionId(string $table, array $row): ?int
    {
        $query = DB::table($table);

        switch ($table) {
            case 'business_calendars':
            case 'contact_statuses':
            case 'task_templates':
            case 'flow_route_capabilities':
            case 'message_template_presets':
            case 'message_templates':
            case 'message_chains':
            case 'campaigns':
            case 'inbound_reply_profiles':
            case 'webinar_schedule_profiles':
                $key = $row['key'] ?? null;

                if (! is_string($key) || trim($key) === '') {
                    return null;
                }

                $query->where('key', $key);
                break;

            case 'message_template_versions':
                if (($row['message_template_id'] ?? null) === null
                    || ($row['version'] ?? null) === null
                ) {
                    return null;
                }

                $query
                    ->where('message_template_id', $row['message_template_id'])
                    ->where('version', $row['version']);
                break;

            case 'message_template_preset_assignments':
                if (($row['message_template_preset_id'] ?? null) === null) {
                    return null;
                }

                $query->where('message_template_preset_id', $row['message_template_preset_id']);
                $this->whereNullable($query, 'surface', $row['surface'] ?? null);
                $this->whereNullable($query, 'definition_key', $row['definition_key'] ?? null);
                $this->whereNullable($query, 'campaign_key', $row['campaign_key'] ?? null);
                $this->whereNullable($query, 'campaign_step', $row['campaign_step'] ?? null);
                $this->whereNullable($query, 'campaign_step_variant_key', $row['campaign_step_variant_key'] ?? null);
                $this->whereNullable($query, 'context_type', $row['context_type'] ?? null);
                $this->whereNullable($query, 'context_id', $row['context_id'] ?? null);
                break;

            case 'message_template_catalog_entries':
                if (($row['message_template_preset_id'] ?? null) === null
                    || ! is_string($row['item_key'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('message_template_preset_id', $row['message_template_preset_id'])
                    ->where('item_key', $row['item_key']);
                break;

            case 'message_chain_versions':
                if (($row['message_chain_id'] ?? null) === null
                    || ($row['version'] ?? null) === null
                ) {
                    return null;
                }

                $query
                    ->where('message_chain_id', $row['message_chain_id'])
                    ->where('version', $row['version']);
                break;

            case 'message_chain_steps':
                if (($row['message_chain_version_id'] ?? null) === null
                    || ! is_string($row['key'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('message_chain_version_id', $row['message_chain_version_id'])
                    ->where('key', $row['key']);
                break;

            case 'message_chain_step_variants':
                if (($row['message_chain_step_id'] ?? null) === null
                    || ! is_string($row['key'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('message_chain_step_id', $row['message_chain_step_id'])
                    ->where('key', $row['key']);
                break;

            case 'campaign_steps':
                if (($row['campaign_id'] ?? null) === null
                    || ($row['step_number'] ?? null) === null
                ) {
                    return null;
                }

                $query
                    ->where('campaign_id', $row['campaign_id'])
                    ->where('step_number', $row['step_number']);
                break;

            case 'campaign_step_variants':
                if (($row['campaign_step_id'] ?? null) === null
                    || ! is_string($row['key'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('campaign_step_id', $row['campaign_step_id'])
                    ->where('key', $row['key']);
                break;

            case 'flow_routes':
                $key = $row['key'] ?? null;
                $version = $row['version'] ?? null;

                if (! is_string($key) || trim($key) === '' || $version === null) {
                    return null;
                }

                $query->where('key', $key)->where('version', $version);
                break;

            case 'flow_route_points':
                if (($row['flow_route_id'] ?? null) === null
                    || ! is_string($row['key'] ?? null)
                    || trim((string) $row['key']) === ''
                ) {
                    return null;
                }

                $query
                    ->where('flow_route_id', $row['flow_route_id'])
                    ->where('key', $row['key']);
                break;

            case 'flow_route_trigger_bindings':
                if (($row['flow_route_id'] ?? null) === null
                    || ! is_string($row['trigger_type'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('flow_route_id', $row['flow_route_id'])
                    ->where('trigger_type', $row['trigger_type']);
                $this->whereNullable($query, 'trigger_key', $row['trigger_key'] ?? null);
                $this->whereNullable($query, 'context_type', $row['context_type'] ?? null);
                $this->whereNullable($query, 'context_id', $row['context_id'] ?? null);
                break;

            case 'inbound_reply_intents':
                if (($row['inbound_reply_profile_id'] ?? null) === null
                    || ! is_string($row['key'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('inbound_reply_profile_id', $row['inbound_reply_profile_id'])
                    ->where('key', $row['key']);
                break;

            case 'inbound_reply_rules':
                if (($row['inbound_reply_intent_id'] ?? null) === null
                    || ! is_string($row['match_type'] ?? null)
                    || ! is_string($row['value'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('inbound_reply_intent_id', $row['inbound_reply_intent_id'])
                    ->where('match_type', $row['match_type'])
                    ->where('value', $row['value']);
                break;

            case 'webinar_schedule_profile_items':
                if (($row['webinar_schedule_profile_id'] ?? null) === null
                    || ! is_string($row['key'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('webinar_schedule_profile_id', $row['webinar_schedule_profile_id'])
                    ->where('key', $row['key']);
                break;

            case 'webinar_schedule_profile_chain_bindings':
                if (($row['webinar_schedule_profile_id'] ?? null) === null
                    || ! is_string($row['key'] ?? null)
                    || ! is_string($row['message_area_key'] ?? null)
                ) {
                    return null;
                }

                $query
                    ->where('webinar_schedule_profile_id', $row['webinar_schedule_profile_id'])
                    ->where('key', $row['key'])
                    ->where('message_area_key', $row['message_area_key']);
                break;

            default:
                throw new RuntimeException("No definition identity strategy is defined for [{$table}].");
        }

        $ids = $query->orderBy('id')->pluck('id');

        if ($ids->count() === 1) {
            return (int) $ids->first();
        }

        if ($ids->count() > 1) {
            throw new RuntimeException(
                "Definition identity for [{$table}] matched multiple DEV rows.",
            );
        }

        return null;
    }

    private function whereNullable(Builder $query, string $column, mixed $value): void
    {
        if ($value === null) {
            $query->whereNull($column);

            return;
        }

        $query->where($column, $value);
    }

    /** @param array<string, mixed> $row */
    private function resolveReferenceTargetId(string $table, array $row): int
    {
        $prepared = $this->prepareIdentityOnlyRow($table, $row);
        $targetId = $this->findExistingDefinitionId($table, $prepared);

        if ($targetId !== null) {
            return $targetId;
        }

        throw new RuntimeException(
            sprintf(
                'Production identity-only definition [%s:%s] has no matching DEV preset/config row. This row was not captured as replayable production state, so do not invent it in DEV. Confirm the client config is current or recreate the snapshot if the row is actually production-authored.',
                $table,
                (string) ($row['id'] ?? '?'),
            ),
        );
    }

    /** @param array<string, mixed> $row */
    private function prepareIdentityOnlyRow(string $table, array $row): array
    {
        switch ($table) {
            case 'message_template_versions':
                $row['message_template_id'] = $this->mappedId(
                    'message_templates',
                    $row['message_template_id'] ?? null,
                );
                break;

            case 'message_template_preset_assignments':
            case 'message_template_catalog_entries':
                $row['message_template_preset_id'] = $this->mappedId(
                    'message_template_presets',
                    $row['message_template_preset_id'] ?? null,
                );
                break;

            case 'message_chain_versions':
                $row['message_chain_id'] = $this->mappedId(
                    'message_chains',
                    $row['message_chain_id'] ?? null,
                );
                break;

            case 'message_chain_steps':
                $row['message_chain_version_id'] = $this->mappedId(
                    'message_chain_versions',
                    $row['message_chain_version_id'] ?? null,
                );
                break;

            case 'message_chain_step_variants':
                $row['message_chain_step_id'] = $this->mappedId(
                    'message_chain_steps',
                    $row['message_chain_step_id'] ?? null,
                );
                break;

            case 'campaign_steps':
                $row['campaign_id'] = $this->mappedId('campaigns', $row['campaign_id'] ?? null);
                break;

            case 'campaign_step_variants':
                $row['campaign_step_id'] = $this->mappedId(
                    'campaign_steps',
                    $row['campaign_step_id'] ?? null,
                );
                break;

            case 'flow_route_points':
            case 'flow_route_trigger_bindings':
                $row['flow_route_id'] = $this->mappedId(
                    'flow_routes',
                    $row['flow_route_id'] ?? null,
                );
                break;

            case 'inbound_reply_intents':
                $row['inbound_reply_profile_id'] = $this->mappedId(
                    'inbound_reply_profiles',
                    $row['inbound_reply_profile_id'] ?? null,
                );
                break;

            case 'inbound_reply_rules':
                $row['inbound_reply_intent_id'] = $this->mappedId(
                    'inbound_reply_intents',
                    $row['inbound_reply_intent_id'] ?? null,
                );
                break;

            case 'webinar_schedule_profile_items':
            case 'webinar_schedule_profile_chain_bindings':
                $row['webinar_schedule_profile_id'] = $this->mappedId(
                    'webinar_schedule_profiles',
                    $row['webinar_schedule_profile_id'] ?? null,
                );
                break;
        }

        return $row;
    }

    private function isDefinitionRootTable(string $table): bool
    {
        return in_array($table, [
            'business_calendars',
            'contact_statuses',
            'task_templates',
            'flow_route_capabilities',
            'message_template_presets',
            'message_templates',
            'message_chains',
            'campaigns',
            'flow_routes',
            'inbound_reply_profiles',
            'webinar_schedule_profiles',
        ], true);
    }

    /**
     * @param list<string> $tableOrder
     * @param array<string, list<array{mode: string, data: array<string, mixed>}>> $rowsByTable
     * @return list<string>
     */
    private function runtimeTables(array $tableOrder, array $rowsByTable): array
    {
        $excluded = array_flip(array_merge(
            self::ENVIRONMENT_TABLES,
            self::DEFINITION_TABLES,
            self::REPLAY_BY_IDENTITY_TABLES,
        ));

        return array_values(array_filter(
            $tableOrder,
            fn (string $table): bool =>
                ! isset($excluded[$table])
                && ($rowsByTable[$table] ?? []) !== [],
        ));
    }

    /**
     * @param list<string> $runtimeTables
     * @param array<string, list<array{mode: string, data: array<string, mixed>}>> $rowsByTable
     */
    private function assertRuntimeTablesAreEmpty(array $runtimeTables, array $rowsByTable): void
    {
        $dirty = [];

        foreach ($runtimeTables as $table) {
            if (($rowsByTable[$table] ?? []) === []) {
                continue;
            }

            $count = DB::table($table)->count();

            if ($count > 0) {
                $dirty[$table] = $count;
            }
        }

        if ($dirty === []) {
            return;
        }

        $display = collect($dirty)
            ->map(fn (int $count, string $table): string => "{$table}={$count}")
            ->take(30)
            ->implode(', ');

        throw new RuntimeException(
            'The target DEV database is not clean enough for this one-off production snapshot. Runtime rows already exist in: '.$display.'. Rebuild DEV, install/sync the Slam Dunk configuration, create only the local CRM login user you need, then rerun the dry-run. Do not seed SurfaceShowcaseSeeder before this import.',
        );
    }

    /**
     * @param list<array{mode: string, data: array<string, mixed>}> $rows
     */
    private function replayIdentityRows(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        if ($table !== 'message_template_composition_layers') {
            throw new RuntimeException("No replay strategy is defined for [{$table}].");
        }

        foreach ($rows as $sourceRow) {
            $row = $this->remapRowReferences($table, $sourceRow['data']);
            $identity = $row['identity_key'] ?? null;

            if (! is_string($identity) || trim($identity) === '') {
                throw new RuntimeException('Message template composition layer is missing identity_key.');
            }

            $existingId = DB::table($table)->where('identity_key', $identity)->value('id');
            $sourceId = $row['id'] ?? null;
            unset($row['id']);

            if ($existingId !== null) {
                DB::table($table)->where('id', $existingId)->update($row);

                if ($sourceId !== null) {
                    $this->idMaps[$table][$sourceId] = (int) $existingId;
                }

                continue;
            }

            if ($sourceId !== null && ! DB::table($table)->where('id', $sourceId)->exists()) {
                DB::table($table)->insert(['id' => $sourceId] + $row);
                $this->idMaps[$table][$sourceId] = (int) $sourceId;
                continue;
            }

            $targetId = DB::table($table)->insertGetId($row);

            if ($sourceId !== null) {
                $this->idMaps[$table][$sourceId] = (int) $targetId;
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function remapRowReferences(string $table, array $row): array
    {
        $definition = $this->projectStateDefinitions[$table] ?? null;

        if (is_array($definition)) {
            foreach ($definition['json_columns'] ?? [] as $column) {
                if (! array_key_exists($column, $row) || $row[$column] === null || is_array($row[$column])) {
                    continue;
                }

                if (is_string($row[$column])) {
                    $decoded = json_decode($row[$column], true);

                    if (is_array($decoded)) {
                        $row[$column] = $decoded;
                    }
                }
            }

            foreach ($definition['references'] ?? [] as $column => $referencedTable) {
                if (! array_key_exists($column, $row) || $row[$column] === null) {
                    continue;
                }

                if (isset($this->idMaps[$referencedTable][$row[$column]])) {
                    $row[$column] = $this->idMaps[$referencedTable][$row[$column]];
                }
            }

            foreach ($definition['polymorphic_references'] ?? [] as $reference) {
                $typeColumn = $reference['type_column'] ?? null;
                $idColumn = $reference['id_column'] ?? null;

                if (! is_string($typeColumn) || ! is_string($idColumn)) {
                    continue;
                }

                $type = $row[$typeColumn] ?? null;
                $sourceId = $row[$idColumn] ?? null;
                $referencedTable = is_string($type)
                    ? (($reference['targets'] ?? [])[$type] ?? null)
                    : null;

                if (is_string($referencedTable)
                    && $sourceId !== null
                    && isset($this->idMaps[$referencedTable][$sourceId])
                ) {
                    $row[$idColumn] = $this->idMaps[$referencedTable][$sourceId];
                }
            }

            foreach ($definition['json_path_references'] ?? [] as $column => $pathReferences) {
                $value = $row[$column] ?? null;

                if (! is_array($value)) {
                    continue;
                }

                foreach ($pathReferences as $path => $reference) {
                    if (($reference['deferred'] ?? false) || ! Arr::has($value, $path)) {
                        continue;
                    }

                    $sourceId = Arr::get($value, $path);
                    $referencedTable = $reference['table'] ?? null;

                    if ($sourceId !== null
                        && is_string($referencedTable)
                        && isset($this->idMaps[$referencedTable][$sourceId])
                    ) {
                        Arr::set(
                            $value,
                            $path,
                            $this->idMaps[$referencedTable][$sourceId],
                        );
                    }
                }

                $row[$column] = $value;
            }
        }

        foreach ($this->foreignKeys[$table] ?? [] as $column => $foreignTable) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            if (isset($this->idMaps[$foreignTable][$row[$column]])) {
                $row[$column] = $this->idMaps[$foreignTable][$row[$column]];
            }
        }

        foreach ($row as $column => $type) {
            if (! str_ends_with($column, '_type') || ! is_string($type) || $type === '') {
                continue;
            }

            $idColumn = substr($column, 0, -5).'_id';

            if (! array_key_exists($idColumn, $row) || $row[$idColumn] === null) {
                continue;
            }

            $morphTable = $this->tableForMorphType($type);

            if ($morphTable !== null && isset($this->idMaps[$morphTable][$row[$idColumn]])) {
                $row[$idColumn] = $this->idMaps[$morphTable][$row[$idColumn]];
            }
        }

        if (is_array($definition)) {
            foreach ($definition['json_columns'] ?? [] as $column) {
                if (array_key_exists($column, $row) && is_array($row[$column])) {
                    $row[$column] = json_encode(
                        $row[$column],
                        JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                            | JSON_INVALID_UTF8_SUBSTITUTE
                            | JSON_THROW_ON_ERROR,
                    );
                }
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function makeRuntimeRowInert(string $table, array $row, array &$summary): array
    {
        if ($table === 'message_chain_enrollments'
            && ($row['status'] ?? null) === 'active'
        ) {
            $row['surface'] = $this->isolatedMessageChainSurface(
                $row['surface'] ?? null,
            );
            $summary['isolated_message_chain_enrollments']++;
        }

        if ($table === 'scheduled_messages'
            && in_array($row['status'] ?? null, ['pending', 'sending'], true)
        ) {
            $row['operational_state'] = 'held';

            foreach (['claim_token', 'claim_expires_at'] as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = null;
                }
            }

            $summary['held_scheduled_messages']++;
        }

        if (in_array($table, [
            'automation_event_outbox_events',
            'scheduled_message_outbox_events',
        ], true) && in_array($row['status'] ?? null, ['pending', 'processing'], true)) {
            if (array_key_exists('available_at', $row)) {
                $row['available_at'] = '2099-12-31 23:59:59';
            }

            foreach (['claim_token', 'claim_expires_at'] as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = null;
                }
            }
        }

        return $row;
    }

    private function isolatedMessageChainSurface(mixed $sourceSurface): string
    {
        $source = is_string($sourceSurface) && trim($sourceSurface) !== ''
            ? trim($sourceSurface)
            : 'unspecified';

        return Str::limit(
            'testing:production_snapshot:'.$source,
            96,
            '',
        );
    }

    /** @param array<string, mixed> $overlay */
    private function mergeJsonObject(mixed $value, array $overlay): string
    {
        $base = [];

        if (is_array($value)) {
            $base = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $base = is_array($decoded) ? $decoded : [];
        }

        return (string) json_encode(
            array_replace_recursive($base, $overlay),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    private function tableForMorphType(string $type): ?string
    {
        if (! str_contains($type, '\\')) {
            return null;
        }

        $basename = class_basename($type);

        return Str::snake(Str::pluralStudly($basename));
    }

    /** @return array<string, array<string, mixed>> */
    private function projectStateDefinitions(): array
    {
        $definitions = [];

        foreach ($this->projectStateContracts->sections() as $section) {
            foreach ($section['tables'] as $table => $definition) {
                $definitions[$table] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param list<string> $tables
     * @return array<string, array<string, string>>
     */
    private function foreignKeysForTables(array $tables): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => $this->mysqlForeignKeys($tables),
            'sqlite' => $this->sqliteForeignKeys($tables),
            default => throw new RuntimeException(
                "Production snapshot importer does not support database driver [{$driver}].",
            ),
        };
    }

    /**
     * @param list<string> $tables
     * @return array<string, array<string, string>>
     */
    private function mysqlForeignKeys(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $database = DB::connection()->getDatabaseName();
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $bindings = array_merge([$database], $tables);
        $rows = DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME IN ({$placeholders})
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            $bindings,
        );

        $foreignKeys = [];

        foreach ($rows as $row) {
            $table = (string) $row->TABLE_NAME;
            $column = (string) $row->COLUMN_NAME;
            $foreignTable = (string) $row->REFERENCED_TABLE_NAME;
            $foreignKeys[$table][$column] = $foreignTable;
        }

        return $foreignKeys;
    }

    /**
     * @param list<string> $tables
     * @return array<string, array<string, string>>
     */
    private function sqliteForeignKeys(array $tables): array
    {
        $foreignKeys = [];

        foreach ($tables as $table) {
            $escaped = str_replace('"', '""', $table);

            foreach (DB::select("PRAGMA foreign_key_list(\"{$escaped}\")") as $row) {
                $column = $row->from ?? null;
                $foreignTable = $row->table ?? null;

                if (is_string($column) && is_string($foreignTable)) {
                    $foreignKeys[$table][$column] = $foreignTable;
                }
            }
        }

        return $foreignKeys;
    }

    private function mappedId(string $table, mixed $sourceId): int
    {
        if ($sourceId === null || ! isset($this->idMaps[$table][$sourceId])) {
            throw new RuntimeException(
                sprintf(
                    'Reference mapping for [%s:%s] is unavailable.',
                    $table,
                    is_scalar($sourceId) ? (string) $sourceId : 'null',
                ),
            );
        }

        return (int) $this->idMaps[$table][$sourceId];
    }

    private function requiredTableName(mixed $value, int $lineNumber): string
    {
        if (! is_string($value)
            || $value === ''
            || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1
        ) {
            throw new RuntimeException("Snapshot line {$lineNumber} has an invalid table name.");
        }

        return $value;
    }
}