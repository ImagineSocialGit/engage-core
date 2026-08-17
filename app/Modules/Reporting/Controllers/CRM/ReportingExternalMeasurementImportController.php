<?php

namespace App\Modules\Reporting\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Actions\ImportReportingExternalMeasurementsCsvAction;
use App\Modules\Reporting\Services\ExternalMeasurements\MetaAdsCsvParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class ReportingExternalMeasurementImportController extends Controller
{
    public function create(): View
    {
        return view('crm.reporting.imports.create', [
            'title' => 'Import Ad Platform Report',
            'heading' => 'Import Ad Platform Report',
            'subheading' => 'Add exported ad-platform results so Reporting can compare paid traffic with first-party conversion data.',
            'defaultTimezone' => (string) config('client.timezone', config('app.timezone', 'UTC')),
        ]);
    }

    public function preview(
        Request $request,
        MetaAdsCsvParser $parser,
    ): View|RedirectResponse {
        $validated = $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'account_id' => ['nullable', 'string', 'max:120'],
            'account_timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $token = (string) Str::uuid();
        $storedPath = $validated['csv']->storeAs(
            'reporting-imports',
            $token.'.csv',
            'local',
        );

        if (! is_string($storedPath) || $storedPath === '') {
            throw ValidationException::withMessages([
                'csv' => 'The Meta Ads CSV could not be stored for preview.',
            ]);
        }

        $absolutePath = Storage::disk('local')->path($storedPath);
        $fileHash = hash_file('sha256', $absolutePath);

        try {
            $preview = $parser->parse(
                path: $absolutePath,
                accountId: $validated['account_id'] ?? null,
                accountTimezone: $validated['account_timezone'] ?? null,
                sourceFileHash: is_string($fileHash) ? $fileHash : null,
            );
        } catch (InvalidArgumentException $exception) {
            Storage::disk('local')->delete($storedPath);

            throw ValidationException::withMessages([
                'csv' => $exception->getMessage(),
            ]);
        }

        unset($preview['measurements']);

        return view('crm.reporting.imports.preview', [
            'title' => 'Review Ad Platform Import',
            'heading' => 'Review Ad Platform Import',
            'subheading' => 'Confirm what Reporting recognized before importing the exported measurements.',
            'importToken' => $token,
            'accountId' => $validated['account_id'] ?? null,
            'accountTimezone' => $validated['account_timezone'] ?? null,
            'preview' => $preview,
        ]);
    }

    public function store(
        Request $request,
        ImportReportingExternalMeasurementsCsvAction $import,
    ): RedirectResponse {
        $validated = $request->validate([
            'import_token' => ['required', 'uuid'],
            'account_id' => ['nullable', 'string', 'max:120'],
            'account_timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $storedPath = 'reporting-imports/'.$validated['import_token'].'.csv';

        if (! Storage::disk('local')->exists($storedPath)) {
            throw ValidationException::withMessages([
                'import_token' => 'The previewed import file is no longer available. Upload it again.',
            ]);
        }

        try {
            $result = $import->handle(
                path: Storage::disk('local')->path($storedPath),
                accountId: $validated['account_id'] ?? null,
                accountTimezone: $validated['account_timezone'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'import_token' => $exception->getMessage(),
            ]);
        } finally {
            Storage::disk('local')->delete($storedPath);
        }

        return redirect()
            ->route('crm.reporting.index')
            ->with('success', sprintf(
                'Ad platform report imported. %d row(s) created, %d updated%s.',
                (int) $result['created_count'],
                (int) $result['updated_count'],
                (int) $result['skipped_count'] > 0
                    ? ', '.(int) $result['skipped_count'].' skipped'
                    : '',
            ));
    }
}