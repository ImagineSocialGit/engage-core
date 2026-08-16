<?php

namespace App\Modules\Reporting\Services;

use App\Support\Reporting\Data\ReportingEventDefinition;
use App\Support\Reporting\ReportingEventDefinitionRegistry;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final class ReportingPublicCollectionPolicy
{
    public function __construct(
        private readonly ReportingEventDefinitionRegistry $definitions,
    ) {}

    public function allows(
        Request $request,
        string $eventKey,
        int $eventVersion,
        string $surface,
    ): bool {
        if (config('reporting.collection.browser_enabled') !== true) {
            return false;
        }

        try {
            $definition = $this->definitions->get(
                strtolower(trim($eventKey)),
                $eventVersion,
            );
        } catch (Throwable) {
            return false;
        }

        if (! $definition instanceof ReportingEventDefinition) {
            return false;
        }

        $surface = str_replace('-', '_', strtolower(trim($surface)));
        $host = rtrim(strtolower(trim($request->getHost())), '.');

        if (! $definition->allowsSurface($surface)
            || ! $definition->allowsBrowserHost($host)
        ) {
            return false;
        }

        return $this->isSameOrigin($request);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function normalizeQuery(array $query): array
    {
        $allowedKeys = $this->allowedQueryKeys();
        $unknown = array_values(array_diff(array_keys($query), $allowedKeys));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Reporting browser attribution contains unsupported query keys.',
            );
        }

        $normalized = [];
        $maximumLength = min(
            120,
            max(1, (int) config('reporting.attribution.value_max_length', 120)),
        );

        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException(
                    'Reporting browser attribution values must be scalar.',
                );
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            if (strlen($value) > $maximumLength
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new InvalidArgumentException(
                    'Reporting browser attribution contains an invalid value.',
                );
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    private function isSameOrigin(Request $request): bool
    {
        $origin = trim((string) $request->headers->get('Origin', ''));

        if ($origin === '') {
            return false;
        }

        $parts = parse_url($origin);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['path'], $parts['query'], $parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = rtrim(strtolower((string) $parts['host']), '.');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $originAuthority = $scheme.'://'.$host;

        if ($port !== null
            && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
        ) {
            $originAuthority .= ':'.$port;
        }

        $requestAuthority = strtolower(rtrim($request->getSchemeAndHttpHost(), '/'));

        if (! hash_equals($requestAuthority, $originAuthority)) {
            return false;
        }

        $fetchSite = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));

        return $fetchSite === '' || $fetchSite === 'same-origin';
    }

    /** @return array<int, string> */
    private function allowedQueryKeys(): array
    {
        $utm = config('reporting.attribution.utm_keys', []);
        $clickIds = config('reporting.attribution.click_id_keys', []);
        $keys = [];

        if (is_array($utm)) {
            foreach ($utm as $queryKey) {
                if (is_string($queryKey) && trim($queryKey) !== '') {
                    $keys[] = trim($queryKey);
                }
            }
        }

        if (is_array($clickIds)) {
            foreach (array_keys($clickIds) as $queryKey) {
                if (is_string($queryKey) && trim($queryKey) !== '') {
                    $keys[] = trim($queryKey);
                }
            }
        }

        return array_values(array_unique($keys));
    }
}