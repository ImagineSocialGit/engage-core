<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateResumeManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectStateController extends Controller
{
    public function __construct(
        private readonly ProjectStateResumeManager $resumeManager,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeOwner($request);

        return $this->view();
    }

    public function export(
        Request $request,
        ProjectStateManager $projectState,
    ): StreamedResponse {
        $this->authorizeOwner($request);
        $this->validatePassword($request);

        try {
            $document = $projectState->export();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'project_state' => $exception->getMessage(),
            ]);
        }

        $contents = $projectState->encode($document);
        $clientKey = preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '-',
            (string) config('client.key', 'client'),
        ) ?: 'client';

        $filename = sprintf(
            '%s-project-state-v%d-%s.json',
            strtolower($clientKey),
            (int) config('project_state.version', 1),
            now('UTC')->format('Ymd-His'),
        );

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            $filename,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function import(
        Request $request,
        ProjectStateManager $projectState,
    ): View {
        $this->authorizeOwner($request);

        $validated = $request->validate([
            'state_file' => [
                'required',
                'file',
                'max:'.max(1, (int) config('project_state.max_upload_kilobytes', 102400)),
            ],
            'operation' => ['required', 'in:validate,apply'],
            'current_password' => ['nullable', 'string'],
            'confirmation' => ['nullable', 'string'],
        ]);

        $contents = file_get_contents($request->file('state_file')->getRealPath());

        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([
                'state_file' => 'The uploaded project-state file could not be read.',
            ]);
        }

        try {
            $document = $projectState->decode($contents);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'state_file' => $exception->getMessage(),
            ]);
        }

        if ($validated['operation'] === 'validate') {
            return $this->view($projectState->validate($document));
        }

        $this->validatePassword($request);

        if (($validated['confirmation'] ?? null) !== 'IMPORT') {
            throw ValidationException::withMessages([
                'confirmation' => 'Type IMPORT exactly to apply the project-state file.',
            ]);
        }

        $report = $projectState->validate($document);

        if (! $report['valid']) {
            return $this->view($report);
        }

        try {
            $report = $projectState->import($document);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'state_file' => $exception->getMessage(),
            ]);
        }

        return $this->view($report);
    }

    public function resume(Request $request): View
    {
        $this->authorizeOwner($request);

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:80'],
            'current_password' => ['required', 'string'],
            'confirmation' => ['required', 'string'],
        ]);

        $this->validatePassword($request);

        if ($validated['confirmation'] !== 'RESUME') {
            throw ValidationException::withMessages([
                'confirmation' => 'Type RESUME exactly to continue the selected imported activity.',
            ]);
        }

        try {
            $resumeReport = $this->resumeManager->resume($validated['category']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'category' => $exception->getMessage(),
            ]);
        }

        return $this->view(resumeReport: $resumeReport);
    }

    /**
     * @param array<string, mixed>|null $report
     * @param array<string, mixed>|null $resumeReport
     */
    private function view(
        ?array $report = null,
        ?array $resumeReport = null,
    ): View {

        return view('crm.project-state.index', [
            'title' => 'Project State',
            'heading' => 'Project State',
            'report' => $report,
            'resumeReport' => $resumeReport,
            'resumeSummary' => $this->resumeManager->summary(),
            'resumeBatchSize' => min(
                5000,
                max(1, (int) config('project_state.resume_batch_size', 500)),
            ),
            'format' => (string) config('project_state.format'),
            'formatVersion' => (int) config('project_state.version'),
            'maxUploadMegabytes' => round(
                max(1, (int) config('project_state.max_upload_kilobytes', 102400)) / 1024,
                1,
            ),
        ]);
    }

    private function authorizeOwner(Request $request): void
    {
        $authorizedEmail = strtolower(trim(
            (string) config('project_state.authorized_email', '')
        ));
        $userEmail = strtolower(trim((string) $request->user()?->email));

        abort_unless(
            $authorizedEmail !== ''
                && $userEmail !== ''
                && hash_equals($authorizedEmail, $userEmail),
            403,
        );
    }

    private function validatePassword(Request $request): void
    {
        $password = (string) $request->input('current_password', '');
        $passwordHash = (string) $request->user()?->getAuthPassword();

        if ($password === ''
            || $passwordHash === ''
            || ! Hash::check($password, $passwordHash)
        ) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }
    }
}