<?php

namespace App\Modules\Webinars\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class WebinarAttendanceContactFilterCriterion implements ContactFilterCriterion
{
    private const OUTCOMES = [
        'attended',
        'missed',
    ];

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
        return 'Webinar attendance';
    }

    public function help(): ?string
    {
        return 'Match historical attended/missed outcomes for any Webinar, one Webinar Type, one session, or sessions before a selected session.';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        $options = [
            ['value' => 'any:attended', 'label' => 'Any webinar — Attended'],
            ['value' => 'any:missed', 'label' => 'Any webinar — Missed'],
        ];

        $seriesOptions = WebinarSeries::query()
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('title')
            ->orderBy('id')
            ->get(['slug', 'title'])
            ->flatMap(function (WebinarSeries $series): array {
                $slug = trim((string) $series->slug);
                $title = trim((string) $series->title);
                $label = $title !== '' ? $title : $slug;

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

        $sessionOptions = Webinar::query()
            ->with('webinarSeries:id,title,slug')
            ->whereNotNull('webinar_series_id')
            ->whereNotNull('starts_at')
            ->visible()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get(['id', 'webinar_series_id', 'title', 'starts_at', 'timezone'])
            ->flatMap(function (Webinar $webinar): array {
                $seriesTitle = trim((string) ($webinar->webinarSeries?->title ?? ''));
                $title = $seriesTitle !== ''
                    ? $seriesTitle
                    : trim((string) $webinar->title);
                $startsAt = $webinar->starts_at?->copy()
                    ->setTimezone($webinar->timezone)
                    ->format('M j, Y · g:i A T');
                $label = trim(implode(' — ', array_filter([$title, $startsAt])));

                $id = (int) $webinar->getKey();

                return [
                    ...array_map(
                        fn (string $outcome): array => [
                            'value' => "session:{$id}:{$outcome}",
                            'label' => $label.' — '.ucfirst($outcome),
                        ],
                        self::OUTCOMES,
                    ),
                    ...array_map(
                        fn (string $outcome): array => [
                            'value' => "before_session:{$id}:{$outcome}",
                            'label' => 'Before '.$label.' — '.ucfirst($outcome),
                        ],
                        self::OUTCOMES,
                    ),
                ];
            })
            ->values()
            ->all();

        return [
            ...$options,
            ...$seriesOptions,
            ...$sessionOptions,
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

            if (in_array($parts[0], ['session', 'before_session'], true)
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
                        ->whereColumn('audience_wr.contact_id', 'contacts.id');

                    if ($target['scope'] === 'series') {
                        $subquery
                            ->join('webinars as audience_w', 'audience_w.id', '=', 'audience_wr.webinar_id')
                            ->join('webinar_series as audience_ws', 'audience_ws.id', '=', 'audience_w.webinar_series_id')
                            ->where('audience_ws.slug', $target['identity']);
                    } elseif ($target['scope'] === 'session') {
                        $subquery->where('audience_wr.webinar_id', (int) $target['identity']);
                    } elseif ($target['scope'] === 'before_session') {
                        $subquery
                            ->join('webinars as audience_w', 'audience_w.id', '=', 'audience_wr.webinar_id')
                            ->where(
                                'audience_w.starts_at',
                                '<',
                                function (QueryBuilder $anchor) use ($target): void {
                                    $anchor
                                        ->select('anchor_webinar.starts_at')
                                        ->from('webinars as anchor_webinar')
                                        ->where('anchor_webinar.id', (int) $target['identity'])
                                        ->limit(1);
                                },
                            );
                    }

                    $this->applyOutcome($subquery, $target['outcome']);
                });
            }
        });
    }

    /**
     * @param QueryBuilder $query
     */
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
}