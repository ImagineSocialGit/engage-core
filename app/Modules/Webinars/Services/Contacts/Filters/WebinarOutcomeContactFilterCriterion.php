<?php

namespace App\Modules\Webinars\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class WebinarOutcomeContactFilterCriterion implements ContactFilterCriterion
{
    private const OUTCOMES = [
        'attended',
        'missed',
    ];

    public function key(): string
    {
        return 'webinar_outcome';
    }

    public function sortOrder(): int
    {
        return 70;
    }

    public function label(): string
    {
        return 'Webinar outcome';
    }

    public function help(): ?string
    {
        return 'Match the latest resolved attended or missed outcome for a webinar series.';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        return WebinarSeries::query()
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('title')
            ->orderBy('id')
            ->get(['slug', 'title'])
            ->flatMap(function (WebinarSeries $series): array {
                $slug = trim((string) $series->slug);
                $title = trim((string) $series->title);

                return array_map(
                    fn (string $outcome): array => [
                        'value' => "{$slug}:{$outcome}",
                        'label' => ($title !== '' ? $title : $slug).' — '.ucfirst($outcome),
                    ],
                    self::OUTCOMES,
                );
            })
            ->values()
            ->all();
    }

    /**
     * Normalization intentionally validates the durable semantic shape rather
     * than requiring the series to exist right now. That lets persisted
     * Campaign eligibility fail closed if a series is temporarily unavailable
     * instead of silently deleting the criterion.
     *
     * @return array<int, string>
     */
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
            $separator = strrpos($value, ':');

            if ($separator === false) {
                continue;
            }

            $seriesSlug = substr($value, 0, $separator);
            $outcome = substr($value, $separator + 1);

            if (
                preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $seriesSlug) !== 1
                || ! in_array($outcome, self::OUTCOMES, true)
            ) {
                continue;
            }

            $normalized[] = "{$seriesSlug}:{$outcome}";
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
                        ->from('webinar_registrations as wr')
                        ->join('webinars as w', 'w.id', '=', 'wr.webinar_id')
                        ->join('webinar_series as ws', 'ws.id', '=', 'w.webinar_series_id')
                        ->whereColumn('wr.contact_id', 'contacts.id')
                        ->where('ws.slug', $target['series_slug'])
                        ->where('wr.status', $target['outcome'])
                        ->whereNotExists(function (QueryBuilder $newer): void {
                            $newer
                                ->selectRaw('1')
                                ->from('webinar_registrations as nwr')
                                ->join('webinars as nw', 'nw.id', '=', 'nwr.webinar_id')
                                ->whereColumn('nwr.contact_id', 'wr.contact_id')
                                ->whereColumn('nw.webinar_series_id', 'w.webinar_series_id')
                                ->whereIn('nwr.status', self::OUTCOMES)
                                ->where(function (QueryBuilder $newer): void {
                                    $newer
                                        ->whereRaw(
                                            'COALESCE(nw.starts_at, nwr.registered_at, nwr.created_at) > '
                                            .'COALESCE(w.starts_at, wr.registered_at, wr.created_at)'
                                        )
                                        ->orWhere(function (QueryBuilder $sameOccurrence): void {
                                            $sameOccurrence
                                                ->whereRaw(
                                                    'COALESCE(nw.starts_at, nwr.registered_at, nwr.created_at) = '
                                                    .'COALESCE(w.starts_at, wr.registered_at, wr.created_at)'
                                                )
                                                ->whereColumn('nwr.id', '>', 'wr.id');
                                        });
                                });
                        });
                });
            }
        });
    }

    /**
     * @param array<int, string> $values
     * @return array<int, array{series_slug: string, outcome: string}>
     */
    private function targets(array $values): array
    {
        $targets = [];

        foreach ($this->normalize($values) as $value) {
            $separator = strrpos($value, ':');

            if ($separator === false) {
                continue;
            }

            $targets[] = [
                'series_slug' => substr($value, 0, $separator),
                'outcome' => substr($value, $separator + 1),
            ];
        }

        return $targets;
    }
}