<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Support\ProcessHighway\ProcessHighwayReadService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcessHighwayController extends Controller
{
    public function __invoke(
        Request $request,
        ProcessHighwayReadService $processHighway,
    ): View {
        $highway = $processHighway->read();
        $initialHighway = $this->initialHighway($request, $highway);
        $initialQualifierSelection = is_array($initialHighway)
            ? $this->highwayQualifierSelection($initialHighway)
            : $this->initialQualifierSelection($request, $highway);
        $initialContactMode = ($initialHighway['lane_scope'] ?? null) === 'relationship'
            ? 'relationship'
            : 'standard';
        $initialRelationship = $initialContactMode === 'relationship'
            ? $this->queryValue($initialHighway['relationship_key'] ?? null)
            : null;

        return view('crm.process-highway.index', [
            'title' => 'Process Highway',
            'heading' => 'Process Highway',
            'subheading' => 'See how contact facts, follow-up programs, and automations connect across each business process.',
            'highway' => $highway,
            'initialQualifierSelection' => $initialQualifierSelection,
            'initialHighwayKey' => $initialHighway['key'] ?? null,
            'initialSubjectKey' => $initialHighway['subject_key'] ?? null,
            'initialContactMode' => $initialContactMode,
            'initialRelationship' => $initialRelationship,
        ]);
    }

    /**
     * @param array<string, mixed> $graph
     * @return array<string, mixed>|null
     */
    private function initialHighway(Request $request, array $graph): ?array
    {
        $requestedKey = $this->queryValue($request->query('highway'));

        if ($requestedKey === null) {
            return null;
        }

        $highway = collect($graph['highways'] ?? [])->first(
            fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['key'] ?? null) === $requestedKey,
        );

        return is_array($highway) ? $highway : null;
    }

    /**
     * @param array<string, mixed> $highway
     * @return array<string, string>
     */
    private function highwayQualifierSelection(array $highway): array
    {
        $selection = [];

        foreach ($highway['entry_requirements'] ?? [] as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $criterionKey = $this->queryKey($requirement['criterion_key'] ?? null);
            $value = collect($requirement['values'] ?? [])
                ->map(fn (mixed $candidate): ?string => is_array($candidate)
                    ? $this->queryValue($candidate['value'] ?? null)
                    : null)
                ->first(fn (?string $candidate): bool => $candidate !== null);

            if ($criterionKey !== null && is_string($value)) {
                $selection[$criterionKey] = $value;
            }
        }

        ksort($selection);

        return $selection;
    }

    /**
     * @param array<string, mixed> $graph
     * @return array<string, string>
     */
    private function initialQualifierSelection(Request $request, array $graph): array
    {
        $filterKeys = collect($graph['qualifier_filters'] ?? [])
            ->pluck('key')
            ->map(fn (mixed $key): ?string => $this->queryKey($key))
            ->filter()
            ->merge(['status', 'tag'])
            ->unique()
            ->values();
        $selection = [];

        foreach ($filterKeys as $filterKey) {
            $value = $this->queryValue($request->query($filterKey));

            if ($value !== null) {
                $selection[$filterKey] = $value;
            }
        }

        ksort($selection);

        return $selection;
    }

    private function queryKey(mixed $value): ?string
    {
        $value = $this->queryValue($value, 64);

        if ($value === null || preg_match('/\A[a-z][a-z0-9_]*\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function queryValue(mixed $value, int $maximumLength = 191): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === ''
            || mb_strlen($value) > $maximumLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }

        return $value;
    }
}