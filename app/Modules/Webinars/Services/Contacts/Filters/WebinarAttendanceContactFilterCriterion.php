<?php

namespace App\Modules\Webinars\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Core\Contracts\Contacts\ContactFilterCriterionPresentation;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarSeriesHistoryResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

final class WebinarAttendanceContactFilterCriterion implements ContactFilterCriterion, ContactFilterCriterionPresentation
{
    private const OUTCOMES = [
        'attended',
        'missed',
    ];

    /** @var Collection<int, WebinarSeries>|null */
    private ?Collection $seriesCache = null;

    /** @var Collection<int, Webinar>|null */
    private ?Collection $sessionCache = null;

    public function __construct(
        private readonly WebinarSeriesHistoryResolver $historyResolver,
    ) {}

    public function key(): string
    {
        return 'webinar_attendance';
    }

    public function sortOrder(): int
    {
        return 71;
    }

    public function label(): string
    {
        return 'Webinar details';
    }

    public function help(): ?string
    {
        return 'Filter historical Webinar contacts by outcome, Webinar Type, and session.';
    }

    /**
     * Keep a compact fallback option list for generic filter surfaces.
     * The Broadcast audience builder uses presentation() for the hierarchical
     * Webinar Type -> session experience instead of exposing these values flat.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        $options = [
            ['value' => 'any:attended', 'label' => 'Any webinar — Attended'],
            ['value' => 'any:missed', 'label' => 'Any webinar — Missed'],
        ];

        $seriesOptions = $this->series()
            ->flatMap(function (WebinarSeries $series): array {
                $slug = trim((string) $series->slug);
                $label = $this->seriesLabel($series);

                return array_map(
                    fn (string $outcome): array => [
                        'value' => "series:{$slug}:{$outcome}",
                        'label' => $label.' — '.ucfirst($outcome),
                    ],
                    self::OUTCOMES,
                );
            })
            ->values()
            ->all();

        $sessionOptions = $this->sessions()
            ->flatMap(function (Webinar $webinar): array {
                $id = (int) $webinar->getKey();
                $label = $this->sessionLabel($webinar, includeSeries: true);

                return array_map(
                    fn (string $outcome): array => [
                        'value' => "session:{$id}:{$outcome}",
                        'label' => $label.' — '.ucfirst($outcome),
                    ],
                    self::OUTCOMES,
                );
            })
            ->values()
            ->all();

        return [
            ...$options,
            ...$seriesOptions,
            ...$sessionOptions,
        ];
    }

    /** @return array<string, mixed> */
    public function presentation(): array
    {
        return [
            'audience_builder' => [
                'visible' => true,
                'component' => 'webinars.audience-filter',
                'series' => $this->series()
                    ->map(fn (WebinarSeries $series): array => [
                        'value' => trim((string) $series->slug),
                        'label' => $this->seriesLabel($series),
                    ])
                    ->values()
                    ->all(),
                'sessions' => $this->sessions()
                    ->map(fn (Webinar $webinar): array => [
                        'id' => (int) $webinar->getKey(),
                        'series' => trim((string) ($webinar->webinarSeries?->slug ?? '')),
                        'label' => $this->sessionLabel($webinar),
                        'search_label' => $this->sessionLabel($webinar, includeSeries: true),
                    ])
                    ->filter(fn (array $session): bool => $session['series'] !== '')
                    ->values()
                    ->all(),
            ],
        ];
    }

    /** @return array<int, string> */
    public function normalize(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = strtolower(trim($value));
            $parts = explode(':', $value);

            if (count($parts) === 2
                && $parts[0] === 'any'
                && in_array($parts[1], self::OUTCOMES, true)
            ) {
                $normalized[] = $value;

                continue;
            }

            if (count($parts) !== 3 || ! in_array($parts[2], self::OUTCOMES, true)) {
                continue;
            }

            if ($parts[0] === 'series'
                && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $parts[1]) === 1
            ) {
                $normalized[] = $value;

                continue;
            }

            if (in_array($parts[0], [
                'session',
                'before_session',
                'on_or_after_session',
            ], true)
                && ctype_digit($parts[1])
                && (int) $parts[1] > 0
            ) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param Builder<\App\Modules\Core\Models\Contact> $query
     * @param array<int, string> $values
     */
    public function apply(Builder $query, array $values): void
    {
        $targets = $this->targets($values);

        if ($targets === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($targets): void {
            foreach ($targets as $index => $target) {
                $method = $index === 0 ? 'whereExists' : 'orWhereExists';

                $query->{$method}(function (QueryBuilder $subquery) use ($target): void {
                    $subquery
                        ->selectRaw('1')
                        ->from('webinar_registrations as audience_wr')
                        ->join('webinars as audience_w', 'audience_w.id', '=', 'audience_wr.webinar_id')
                        ->whereColumn('audience_wr.contact_id', 'contacts.id')
                        ->whereNull('audience_w.hidden_at');

                    if ($target['scope'] === 'series') {
                        $subquery
                            ->join('webinar_series as audience_ws', 'audience_ws.id', '=', 'audience_w.webinar_series_id')
                            ->where('audience_ws.slug', $target['identity']);
                    } elseif (in_array($target['scope'], [
                        'session',
                        'before_session',
                        'on_or_after_session',
                    ], true)) {
                        $anchorId = (int) $target['identity'];

                        $subquery->where(
                            'audience_w.webinar_series_id',
                            function (QueryBuilder $anchor) use ($anchorId): void {
                                $anchor
                                    ->select('anchor_webinar.webinar_series_id')
                                    ->from('webinars as anchor_webinar')
                                    ->where('anchor_webinar.id', $anchorId)
                                    ->whereNull('anchor_webinar.hidden_at')
                                    ->limit(1);
                            },
                        );

                        $operator = match ($target['scope']) {
                            'before_session' => '<',
                            'on_or_after_session' => '>=',
                            default => '=',
                        };

                        $subquery->where(
                            'audience_w.starts_at',
                            $operator,
                            function (QueryBuilder $anchor) use ($anchorId): void {
                                $anchor
                                    ->select('anchor_webinar.starts_at')
                                    ->from('webinars as anchor_webinar')
                                    ->where('anchor_webinar.id', $anchorId)
                                    ->whereNull('anchor_webinar.hidden_at')
                                    ->limit(1);
                            },
                        );
                    }

                    $this->applyOutcome($subquery, $target['outcome']);
                });
            }
        });
    }

    private function applyOutcome(QueryBuilder $query, string $outcome): void
    {
        if ($outcome === 'attended') {
            $query->where(function (QueryBuilder $attended): void {
                $attended
                    ->where('audience_wr.status', 'attended')
                    ->orWhereNotNull('audience_wr.attended_at');
            });

            return;
        }

        $query->where('audience_wr.status', 'missed');
    }

    /**
     * @param array<int, string> $values
     * @return array<int, array{scope: string, identity: string|null, outcome: string}>
     */
    private function targets(array $values): array
    {
        $targets = [];

        foreach ($this->normalize($values) as $value) {
            $parts = explode(':', $value);

            if (count($parts) === 2) {
                $targets[] = [
                    'scope' => 'any',
                    'identity' => null,
                    'outcome' => $parts[1],
                ];

                continue;
            }

            $targets[] = [
                'scope' => $parts[0],
                'identity' => $parts[1],
                'outcome' => $parts[2],
            ];
        }

        return $targets;
    }

    /** @return Collection<int, WebinarSeries> */
    private function series(): Collection
    {
        if ($this->seriesCache instanceof Collection) {
            return $this->seriesCache;
        }

        return $this->seriesCache = WebinarSeries::query()
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Webinar> */
    private function sessions(): Collection
    {
        if ($this->sessionCache instanceof Collection) {
            return $this->sessionCache;
        }

        $series = $this->series();

        if ($series->isEmpty()) {
            return $this->sessionCache = collect();
        }

        $occurrences = Webinar::query()
            ->with('webinarSeries:id,title,slug')
            ->withCount('registrations')
            ->whereIn('webinar_series_id', $series->pluck('id')->all())
            ->whereNotNull('starts_at')
            ->get();

        $resolved = collect();

        foreach ($series as $webinarSeries) {
            $resolved = $resolved->concat(
                $this->historyResolver->resolve(
                    $webinarSeries,
                    $occurrences
                        ->where('webinar_series_id', $webinarSeries->getKey())
                        ->values(),
                ),
            );
        }

        return $this->sessionCache = $resolved
            ->sortByDesc(fn (Webinar $webinar): string =>
                $webinar->starts_at?->format('Y-m-d H:i:s') ?? ''
            )
            ->values();
    }

    private function seriesLabel(WebinarSeries $series): string
    {
        $title = trim((string) $series->title);
        $slug = trim((string) $series->slug);

        return $title !== '' ? $title : $slug;
    }

    private function sessionLabel(Webinar $webinar, bool $includeSeries = false): string
    {
        $startsAt = $webinar->starts_at?->copy()
            ->setTimezone($webinar->timezone)
            ->format('M j, Y · g:i A T');

        if (! $includeSeries) {
            return $startsAt ?? 'Session #'.$webinar->getKey();
        }

        $seriesTitle = trim((string) ($webinar->webinarSeries?->title ?? ''));
        $title = $seriesTitle !== ''
            ? $seriesTitle
            : trim((string) $webinar->title);

        return trim(implode(' — ', array_filter([$title, $startsAt])))
            ?: 'Session #'.$webinar->getKey();
    }
}