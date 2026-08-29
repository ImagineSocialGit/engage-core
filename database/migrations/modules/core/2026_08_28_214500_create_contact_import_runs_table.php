<?php

use App\Modules\Core\Models\ContactImportRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_import_batch_id')
                ->unique('contact_import_runs_batch_unique')
                ->constrained('contact_import_batches')
                ->cascadeOnDelete();

            $table->string('status', 32)
                ->default(ContactImportRun::STATUS_PENDING)
                ->index();

            $table->text('csv_path');
            $table->string('import_mode', 16);
            $table->json('headers');
            $table->json('mapping');
            $table->string('profile_key')->nullable();
            $table->json('profile_defaults')->nullable();
            $table->json('treatment_selections')->nullable();
            $table->json('post_import_config')->nullable();
            $table->json('processing_stats')->nullable();

            $table->unsignedBigInteger('actor_user_id')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('next_row_number')->default(2);
            $table->unsignedBigInteger('next_byte_offset')->default(0);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finalizing_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(
                ['status', 'updated_at'],
                'contact_import_runs_status_updated_index',
            );
        });

        $failedAt = now();

        DB::table('contact_import_batches')
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('id')
            ->get(['id', 'meta'])
            ->each(function (object $batch) use ($failedAt): void {
                $meta = [];

                if (is_string($batch->meta) && trim($batch->meta) !== '') {
                    $decoded = json_decode($batch->meta, true);
                    $meta = is_array($decoded) ? $decoded : [];
                } elseif (is_array($batch->meta)) {
                    $meta = $batch->meta;
                }

                $meta['failed_at'] = $failedAt->toISOString();
                $meta['failure'] = [
                    'message' => 'This import was started before resumable background import processing was installed and cannot be resumed safely. Re-run the import.',
                    'exception' => null,
                    'reason' => 'legacy_in_flight_import',
                ];

                $processedRows = DB::table('contact_import_occurrences')
                    ->where('contact_import_batch_id', $batch->id)
                    ->count();

                DB::table('contact_import_batches')
                    ->where('id', $batch->id)
                    ->update([
                        'status' => 'failed',
                        'contact_count' => $processedRows,
                        'successful_count' => $processedRows,
                        'meta' => json_encode(
                            $meta,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                        ),
                        'updated_at' => $failedAt,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_runs');
    }
};