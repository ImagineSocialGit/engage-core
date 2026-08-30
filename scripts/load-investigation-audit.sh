#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
readonly OUTPUT_DIR="${ENGAGE_AUDIT_OUTPUT_DIR:-file_dumps}"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

usage() {
    cat <<EOF
Usage:
  bash ${SCRIPT_NAME} import-batch <label> <batch-id>
  bash ${SCRIPT_NAME} broadcast-reset <label> <source> <expected-contact-count>
  bash ${SCRIPT_NAME} broadcast-reset-batch <label> <batch-id> <expected-contact-count>
  bash ${SCRIPT_NAME} broadcast-collect <label> <broadcast-id>

Recommended sequence for each CSV:
  1. Import the CSV and wait for the batch to complete.
  2. bash ${SCRIPT_NAME} import-batch 1k <batch-id>
  3. bash ${SCRIPT_NAME} broadcast-reset-batch 1k <batch-id> 1000
  4. Create/send one Broadcast targeted only to that import batch.
  5. Wait until every recipient is terminal.
  6. bash ${SCRIPT_NAME} broadcast-collect 1k <broadcast-id>

Repeat with:
  label: 5k
  source: local_load_test_5k
  expected-contact-count: 5000

Set ENGAGE_AUDIT_OUTPUT_DIR to override the default file_dumps directory.
EOF
}

require_repo_root() {
    [[ -f artisan ]] || fail "Run this script from the Engage Core repository root."
    command -v php >/dev/null 2>&1 || fail "php is not available on PATH."
    mkdir -p "$OUTPUT_DIR"
}

validate_label() {
    local label="$1"
    [[ -n "$label" ]] || fail "The label cannot be empty."
    [[ "$label" != *[!a-zA-Z0-9_-]* ]] || fail "The label may contain only letters, numbers, underscores, and hyphens."
}

validate_positive_integer() {
    local name="$1"
    local value="$2"
    [[ "$value" =~ ^[1-9][0-9]*$ ]] || fail "${name} must be a positive integer."
}

require_tinker_marker() {
    local output_file="$1"
    local marker="$2"
    local operation="$3"

    if ! grep -Fq "$marker" "$output_file"; then
        printf '\n%s failed. Diagnostic output follows:\n\n' "$operation" >&2
        sed -n '1,240p' "$output_file" >&2
        fail "${operation} did not complete; do not start the controlled Broadcast."
    fi
}

import_batch_audit() {
    local label="$1"
    local batch_id="$2"

    validate_label "$label"
    validate_positive_integer "batch-id" "$batch_id"

    local profile_file="${OUTPUT_DIR}/contact-import-${label}-batch-${batch_id}-page-profile.txt"
    local explain_file="${OUTPUT_DIR}/contact-import-${label}-batch-${batch_id}-membership-explain.txt"
    local indexes_file="${OUTPUT_DIR}/contact-import-${label}-membership-indexes.txt"
    local cohort_file="${OUTPUT_DIR}/contact-import-${label}-batch-${batch_id}-source-distribution.txt"

    ENGAGE_AUDIT_BATCH_ID="$batch_id" php artisan tinker --execute='
        $batchId = (int) getenv("ENGAGE_AUDIT_BATCH_ID");
        $batch = App\Modules\Core\Models\ContactImportBatch::query()->findOrFail($batchId);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = [
                "ms" => $query->time,
                "sql" => $query->sql,
                "bindings" => $query->bindings,
            ];
        });
        $startedAt = microtime(true);
        $view = app(App\Modules\Core\Controllers\ContactImportBatchController::class)->show($batch);
        $controllerFinishedAt = microtime(true);
        $html = $view->render();
        $renderFinishedAt = microtime(true);
        usort($queries, fn (array $left, array $right): int => $right["ms"] <=> $left["ms"]);
        dump([
            "batch" => $batch->fresh()->only([
                "id",
                "name",
                "original_filename",
                "status",
                "contact_count",
                "successful_count",
                "failed_count",
                "imported_at",
            ]),
            "controller_seconds" => round($controllerFinishedAt - $startedAt, 4),
            "render_seconds" => round($renderFinishedAt - $controllerFinishedAt, 4),
            "total_seconds" => round($renderFinishedAt - $startedAt, 4),
            "query_count" => count($queries),
            "total_db_ms" => round(array_sum(array_column($queries, "ms")), 1),
            "paginator_total" => $view->getData()["contacts"]->total(),
            "contacts_count_attribute" => (int) $view->getData()["importBatch"]->contacts_count,
            "peak_memory_mb" => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            "html_bytes" => strlen($html),
            "slowest_queries" => array_slice($queries, 0, 25),
        ]);
    ' >"$profile_file" 2>&1

    ENGAGE_AUDIT_BATCH_ID="$batch_id" php artisan tinker --execute='
        $batchId = (int) getenv("ENGAGE_AUDIT_BATCH_ID");
        $batch = App\Modules\Core\Models\ContactImportBatch::query()->findOrFail($batchId);
        $membership = $batch->importedContactsQuery();
        $countQuery = (clone $membership)->selectRaw("COUNT(*) AS aggregate");
        $pageQuery = (clone $membership)->latest()->limit(50);
        dump([
            "batch_id" => $batchId,
            "membership_sql" => $membership->toSql(),
            "membership_bindings" => $membership->getBindings(),
            "count_plan" => DB::select(
                "EXPLAIN ANALYZE ".$countQuery->toSql(),
                $countQuery->getBindings(),
            ),
            "page_plan" => DB::select(
                "EXPLAIN ANALYZE ".$pageQuery->toSql(),
                $pageQuery->getBindings(),
            ),
        ]);
    ' >"$explain_file" 2>&1

    php artisan tinker --execute='
        dump(DB::table("information_schema.statistics")
            ->whereRaw("table_schema = database()")
            ->whereIn("table_name", ["contacts", "contact_import_occurrences"])
            ->select([
                "table_name",
                "index_name",
                "seq_in_index",
                "column_name",
                "non_unique",
                "cardinality",
            ])
            ->orderBy("table_name")
            ->orderBy("index_name")
            ->orderBy("seq_in_index")
            ->get()
            ->all());
    ' >"$indexes_file" 2>&1

    ENGAGE_AUDIT_BATCH_ID="$batch_id" php artisan tinker --execute='
        $batchId = (int) getenv("ENGAGE_AUDIT_BATCH_ID");
        $batch = App\Modules\Core\Models\ContactImportBatch::query()->findOrFail($batchId);
        dump((clone $batch->importedContactsQuery())
            ->select(["contacts.source", "contacts.subsource"])
            ->selectRaw("COUNT(*) AS contact_count")
            ->groupBy("contacts.source", "contacts.subsource")
            ->orderByDesc("contact_count")
            ->get()
            ->all());
    ' >"$cohort_file" 2>&1

    printf 'Created:\n  %s\n  %s\n  %s\n  %s\n' \
        "$profile_file" \
        "$explain_file" \
        "$indexes_file" \
        "$cohort_file"
}

broadcast_reset() {
    local label="$1"
    local source="$2"
    local expected_count="$3"

    validate_label "$label"
    [[ -n "$source" ]] || fail "The source cannot be empty."
    validate_positive_integer "expected-contact-count" "$expected_count"

    local baseline_file="${OUTPUT_DIR}/broadcast-${label}-clean-baseline.txt"

    if ! ENGAGE_AUDIT_SOURCE="$source" ENGAGE_AUDIT_EXPECTED_COUNT="$expected_count" php artisan tinker --execute='
        $source = (string) getenv("ENGAGE_AUDIT_SOURCE");
        $expectedCount = (int) getenv("ENGAGE_AUDIT_EXPECTED_COUNT");
        $contactCount = App\Modules\Core\Models\Contact::query()
            ->where("source", $source)
            ->count();
        if ($contactCount !== $expectedCount) {
            throw new RuntimeException(
                "Source [{$source}] resolved {$contactCount} Contacts; expected {$expectedCount}. Counters were not reset.",
            );
        }
        DB::statement("TRUNCATE TABLE performance_schema.table_io_waits_summary_by_index_usage");
        DB::statement("TRUNCATE TABLE performance_schema.events_statements_summary_by_digest");
        $usage = DB::table("performance_schema.table_io_waits_summary_by_index_usage")
            ->whereRaw("OBJECT_SCHEMA = DATABASE()")
            ->whereIn("OBJECT_NAME", [
                "broadcasts",
                "broadcast_recipients",
                "scheduled_messages",
                "scheduled_message_delivery_attempts",
                "scheduled_message_outbox_events",
                "scheduled_message_render_contexts",
            ])
            ->whereNotNull("INDEX_NAME")
            ->select([
                "OBJECT_NAME as table_name",
                "INDEX_NAME as index_name",
                "COUNT_READ as reads",
                "COUNT_WRITE as writes",
                "COUNT_FETCH as fetches",
            ])
            ->orderBy("OBJECT_NAME")
            ->orderBy("INDEX_NAME")
            ->get();
        $nonZero = $usage->filter(
            fn ($row): bool => (int) $row->reads !== 0
                || (int) $row->writes !== 0
                || (int) $row->fetches !== 0,
        )->values();
        if ($nonZero->isNotEmpty()) {
            throw new RuntimeException("Tracked counters were not zero immediately after reset.");
        }
        dump([
            "source" => $source,
            "expected_contact_count" => $expectedCount,
            "actual_contact_count" => $contactCount,
            "reset_at" => now()->toIso8601String(),
            "tracked_index_rows" => $usage->count(),
            "non_zero_after_reset" => $nonZero->all(),
            "broadcast_recipient_unique_index" => $usage
                ->firstWhere("index_name", "broadcast_recipients_broadcast_id_contact_id_unique"),
        ]);
        dump("ENGAGE_AUDIT_BASELINE_OK");
    ' >"$baseline_file" 2>&1; then
        sed -n '1,240p' "$baseline_file" >&2
        fail "Source-based Broadcast baseline command failed; do not start the controlled Broadcast."
    fi

    require_tinker_marker "$baseline_file" "ENGAGE_AUDIT_BASELINE_OK" "Source-based Broadcast baseline"

    printf 'Created:\n  %s\n\nNow send exactly one Broadcast targeted to Source = %s.\n' "$baseline_file" "$source"
}

broadcast_reset_batch() {
    local label="$1"
    local batch_id="$2"
    local expected_count="$3"

    validate_label "$label"
    validate_positive_integer "batch-id" "$batch_id"
    validate_positive_integer "expected-contact-count" "$expected_count"

    local baseline_file="${OUTPUT_DIR}/broadcast-${label}-batch-${batch_id}-clean-baseline.txt"

    if ! ENGAGE_AUDIT_BATCH_ID="$batch_id" ENGAGE_AUDIT_EXPECTED_COUNT="$expected_count" php artisan tinker --execute='
        $batchId = (int) getenv("ENGAGE_AUDIT_BATCH_ID");
        $expectedCount = (int) getenv("ENGAGE_AUDIT_EXPECTED_COUNT");
        $batch = App\Modules\Core\Models\ContactImportBatch::query()->findOrFail($batchId);
        $contactCount = $batch->importedContactsQuery()->count();
        if ($contactCount !== $expectedCount) {
            throw new RuntimeException(
                "Import batch [{$batchId}] resolved {$contactCount} Contacts; expected {$expectedCount}. Counters were not reset.",
            );
        }
        DB::statement("TRUNCATE TABLE performance_schema.table_io_waits_summary_by_index_usage");
        DB::statement("TRUNCATE TABLE performance_schema.events_statements_summary_by_digest");
        $usage = DB::table("performance_schema.table_io_waits_summary_by_index_usage")
            ->whereRaw("OBJECT_SCHEMA = DATABASE()")
            ->whereIn("OBJECT_NAME", [
                "broadcasts",
                "broadcast_recipients",
                "scheduled_messages",
                "scheduled_message_delivery_attempts",
                "scheduled_message_outbox_events",
                "scheduled_message_render_contexts",
            ])
            ->whereNotNull("INDEX_NAME")
            ->select([
                "OBJECT_NAME as table_name",
                "INDEX_NAME as index_name",
                "COUNT_READ as reads",
                "COUNT_WRITE as writes",
                "COUNT_FETCH as fetches",
            ])
            ->orderBy("OBJECT_NAME")
            ->orderBy("INDEX_NAME")
            ->get();
        $nonZero = $usage->filter(
            fn ($row): bool => (int) $row->reads !== 0
                || (int) $row->writes !== 0
                || (int) $row->fetches !== 0,
        )->values();
        if ($nonZero->isNotEmpty()) {
            throw new RuntimeException("Tracked counters were not zero immediately after reset.");
        }
        dump([
            "batch" => $batch->only(["id", "name", "original_filename", "status", "contact_count"]),
            "expected_contact_count" => $expectedCount,
            "actual_contact_count" => $contactCount,
            "reset_at" => now()->toIso8601String(),
            "tracked_index_rows" => $usage->count(),
            "non_zero_after_reset" => $nonZero->all(),
            "broadcast_recipient_unique_index" => $usage
                ->firstWhere("index_name", "broadcast_recipients_broadcast_id_contact_id_unique"),
        ]);
        dump("ENGAGE_AUDIT_BASELINE_OK");
    ' >"$baseline_file" 2>&1; then
        sed -n '1,240p' "$baseline_file" >&2
        fail "Batch-based Broadcast baseline command failed; do not start the controlled Broadcast."
    fi

    require_tinker_marker "$baseline_file" "ENGAGE_AUDIT_BASELINE_OK" "Batch-based Broadcast baseline"

    printf 'Created:\n  %s\n\nNow send exactly one Broadcast targeted to import batch ID %s.\n' \
        "$baseline_file" \
        "$batch_id"
}

broadcast_collect() {
    local label="$1"
    local broadcast_id="$2"

    validate_label "$label"
    validate_positive_integer "broadcast-id" "$broadcast_id"

    local summary_file="${OUTPUT_DIR}/broadcast-${label}-broadcast-${broadcast_id}-summary.txt"
    local usage_file="${OUTPUT_DIR}/broadcast-${label}-broadcast-${broadcast_id}-index-usage.txt"
    local plans_file="${OUTPUT_DIR}/broadcast-${label}-broadcast-${broadcast_id}-query-plans.txt"
    local digests_file="${OUTPUT_DIR}/broadcast-${label}-broadcast-${broadcast_id}-query-digests.txt"
    local sizes_file="${OUTPUT_DIR}/broadcast-${label}-broadcast-${broadcast_id}-table-sizes.txt"

    # Capture attributable counters before any diagnostic query below can add reads.
    php artisan tinker --execute='
        dump(DB::table("performance_schema.table_io_waits_summary_by_index_usage")
            ->whereRaw("OBJECT_SCHEMA = DATABASE()")
            ->whereIn("OBJECT_NAME", [
                "broadcasts",
                "broadcast_recipients",
                "scheduled_messages",
                "scheduled_message_delivery_attempts",
                "scheduled_message_outbox_events",
                "scheduled_message_render_contexts",
            ])
            ->whereNotNull("INDEX_NAME")
            ->select([
                "OBJECT_NAME as table_name",
                "INDEX_NAME as index_name",
                "COUNT_READ as reads",
                "COUNT_WRITE as writes",
                "COUNT_FETCH as fetches",
            ])
            ->orderBy("OBJECT_NAME")
            ->orderByDesc("COUNT_READ")
            ->get()
            ->all());
    ' >"$usage_file" 2>&1

    # Capture attributable statement shapes before the summary and plan diagnostics run.
    php artisan tinker --execute='
        $consumers = DB::table("performance_schema.setup_consumers")
            ->whereIn("NAME", [
                "events_statements_current",
                "events_statements_history",
                "events_statements_history_long",
                "statements_digest",
            ])
            ->select(["NAME as consumer", "ENABLED as enabled"])
            ->orderBy("NAME")
            ->get();
        $digests = DB::table("performance_schema.events_statements_summary_by_digest")
            ->selectRaw("SCHEMA_NAME as schema_name, DIGEST_TEXT as sql_shape, COUNT_STAR as executions, SUM_ROWS_EXAMINED as rows_examined, SUM_ROWS_SENT as rows_sent, SUM_ROWS_AFFECTED as rows_affected, SUM_SELECT_SCAN as select_scans, SUM_NO_INDEX_USED as no_index_used, ROUND(SUM_TIMER_WAIT/1000000000000,3) as total_seconds, ROUND(AVG_TIMER_WAIT/1000000000,3) as avg_ms")
            ->where(function ($query): void {
                $query->where("DIGEST_TEXT", "like", "%broadcast_recipients%")
                    ->orWhere("DIGEST_TEXT", "like", "%broadcasts%");
            })
            ->orderByDesc("SUM_ROWS_EXAMINED")
            ->limit(100)
            ->get();
        dump([
            "statement_consumers" => $consumers->all(),
            "broadcast_digests" => $digests->all(),
        ]);
    ' >"$digests_file" 2>&1

    ENGAGE_AUDIT_BROADCAST_ID="$broadcast_id" php artisan tinker --execute='
        $broadcastId = (int) getenv("ENGAGE_AUDIT_BROADCAST_ID");
        $broadcast = App\Modules\Broadcasts\Models\Broadcast::query()->findOrFail($broadcastId);
        $recipientStatuses = DB::table("broadcast_recipients")
            ->where("broadcast_id", $broadcastId)
            ->selectRaw("status, COUNT(*) AS rows_count")
            ->groupBy("status")
            ->orderBy("status")
            ->get();
        $messageStatuses = DB::table("broadcast_recipients as recipients")
            ->join("scheduled_messages as messages", "messages.id", "=", "recipients.scheduled_message_id")
            ->where("recipients.broadcast_id", $broadcastId)
            ->selectRaw("messages.status, COUNT(*) AS rows_count")
            ->groupBy("messages.status")
            ->orderBy("messages.status")
            ->get();
        $recipientCount = DB::table("broadcast_recipients")
            ->where("broadcast_id", $broadcastId)
            ->count();
        $linkedMessageCount = DB::table("broadcast_recipients")
            ->where("broadcast_id", $broadcastId)
            ->whereNotNull("scheduled_message_id")
            ->distinct()
            ->count("scheduled_message_id");
        $missingMessages = DB::table("broadcast_recipients as recipients")
            ->leftJoin("scheduled_messages as messages", "messages.id", "=", "recipients.scheduled_message_id")
            ->where("recipients.broadcast_id", $broadcastId)
            ->whereNotNull("recipients.scheduled_message_id")
            ->whereNull("messages.id")
            ->count();
        $duplicateMessageAssignments = DB::query()
            ->fromSub(
                DB::table("broadcast_recipients")
                    ->where("broadcast_id", $broadcastId)
                    ->whereNotNull("scheduled_message_id")
                    ->selectRaw("scheduled_message_id, COUNT(*) AS assignments")
                    ->groupBy("scheduled_message_id")
                    ->havingRaw("COUNT(*) > 1"),
                "duplicates",
            )
            ->count();
        $attemptCount = DB::table("scheduled_message_delivery_attempts as attempts")
            ->join("broadcast_recipients as recipients", "recipients.scheduled_message_id", "=", "attempts.scheduled_message_id")
            ->where("recipients.broadcast_id", $broadcastId)
            ->count();
        $outboxCount = DB::table("scheduled_message_outbox_events as outbox")
            ->join("broadcast_recipients as recipients", "recipients.scheduled_message_id", "=", "outbox.scheduled_message_id")
            ->where("recipients.broadcast_id", $broadcastId)
            ->count();
        dump([
            "broadcast" => $broadcast->only([
                "id",
                "name",
                "channel",
                "status",
                "recipient_count",
                "successful_count",
                "failed_count",
                "scheduled_at",
                "completed_at",
                "created_at",
                "updated_at",
            ]),
            "recipient_count" => $recipientCount,
            "recipient_statuses" => $recipientStatuses->all(),
            "distinct_linked_message_count" => $linkedMessageCount,
            "message_statuses" => $messageStatuses->all(),
            "missing_linked_messages" => $missingMessages,
            "duplicate_message_assignments" => $duplicateMessageAssignments,
            "delivery_attempt_count" => $attemptCount,
            "outbox_event_count" => $outboxCount,
        ]);
    ' >"$summary_file" 2>&1

    ENGAGE_AUDIT_BROADCAST_ID="$broadcast_id" php artisan tinker --execute='
        $broadcastId = (int) getenv("ENGAGE_AUDIT_BROADCAST_ID");
        dump([
            "completion_plan" => DB::select(
                "EXPLAIN ANALYZE SELECT 1 FROM broadcast_recipients WHERE broadcast_id = ? AND status IN (?, ?) LIMIT 1",
                [$broadcastId, "pending", "scheduled"],
            ),
            "processed_count_plan" => DB::select(
                "EXPLAIN ANALYZE SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id = ? AND status != ?",
                [$broadcastId, "pending"],
            ),
            "terminal_page_plan" => DB::select(
                "EXPLAIN ANALYZE SELECT id FROM broadcast_recipients WHERE broadcast_id = ? AND status = ? ORDER BY id LIMIT 100",
                [$broadcastId, "sent"],
            ),
        ]);
    ' >"$plans_file" 2>&1

    php artisan tinker --execute='
        dump(DB::table("information_schema.tables")
            ->selectRaw("table_name, table_rows, ROUND(data_length/1024/1024,2) AS data_mb, ROUND(index_length/1024/1024,2) AS index_mb, ROUND((data_length+index_length)/1024/1024,2) AS total_mb")
            ->whereRaw("table_schema = database()")
            ->whereIn("table_name", [
                "broadcasts",
                "broadcast_recipients",
                "scheduled_messages",
                "scheduled_message_delivery_attempts",
                "scheduled_message_outbox_events",
                "scheduled_message_render_contexts",
            ])
            ->orderByDesc("total_mb")
            ->get()
            ->all());
    ' >"$sizes_file" 2>&1

    printf 'Created:\n  %s\n  %s\n  %s\n  %s\n  %s\n' \
        "$summary_file" \
        "$usage_file" \
        "$plans_file" \
        "$digests_file" \
        "$sizes_file"
}

main() {
    local command="${1:-}"

    case "$command" in
        import-batch)
            [[ $# -eq 3 ]] || { usage >&2; exit 1; }
            require_repo_root
            import_batch_audit "$2" "$3"
            ;;
        broadcast-reset)
            [[ $# -eq 4 ]] || { usage >&2; exit 1; }
            require_repo_root
            broadcast_reset "$2" "$3" "$4"
            ;;
        broadcast-reset-batch)
            [[ $# -eq 4 ]] || { usage >&2; exit 1; }
            require_repo_root
            broadcast_reset_batch "$2" "$3" "$4"
            ;;
        broadcast-collect)
            [[ $# -eq 3 ]] || { usage >&2; exit 1; }
            require_repo_root
            broadcast_collect "$2" "$3"
            ;;
        -h|--help|help)
            usage
            ;;
        *)
            usage >&2
            exit 1
            ;;
    esac
}

main "$@"
