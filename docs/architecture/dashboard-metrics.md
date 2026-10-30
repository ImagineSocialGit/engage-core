# CRM Dashboard Metrics

## Purpose and ownership

The CRM dashboard supports two independent extension points:

- `DashboardPanelProvider` supplies actionable work and context panels in configured slots.
- `DashboardMetricProvider` supplies compact headline measurements, including counts and currency totals.

Core owns metric selection, module-availability gating, display formatting, and dashboard rendering. Each contributing module owns its data query, access checks, measurement period, source-of-truth semantics, and any linked workspace. The metric registry must not depend on an individual feature module or a third-party provider.

Merely enabling a module or installing a Composer package does not place its metrics on the dashboard. Headline metrics are explicitly selected through preset or client configuration. Missing, disabled, unavailable, or invalid metrics are excluded. This extension requires no persistence or migration.

## Provider contract

Implement `App\Support\Dashboard\Contracts\DashboardMetricProvider` and tag the implementation from the owning **enabled module's** service provider:

```php
$this->app->tag(
    [ExampleDashboardMetricProvider::class],
    DashboardMetricRegistry::providerTag(),
);
```

The example is illustrative, not a class supplied by Core. Feature module implementations must remain in their owning module or package; do not register them globally in Core.

`key()` supplies a stable registered metric key (for example `example.total`). `module()` supplies the owning module key. `metric(Request $request)` returns `null` if the measurement is unavailable or unauthorized, or an array with:

| Key | Contract |
| --- | --- |
| `label` | Required nonempty display label |
| `format` | Required `count` or `currency` |
| `value` | Required integer; counts are whole units, currencies use integer minor units |
| `currency` | Uppercase ISO 4217 code for currency values (default `USD`) |
| `minor_unit_digits` | Currency precision `0`–`3` (default `2`); provider must supply the appropriate currency scale |
| `period` | Optional human-readable interval |
| `href` | Optional root-relative CRM path or same-host HTTP(S) URL; never expose unauthorized record URLs |
| `priority` | Optional integer ordering preference overridden by client configuration |

The registry formats currency without floating-point arithmetic. USD uses `$` and other currency codes render explicitly (for example `EUR 1,234.00`). Formatting a number does not constitute currency conversion, accounting, or financial reconciliation. Invalid payloads are skipped, not represented as zero.

Providers must restrict queries to data the requesting CRM user may access and bound expensive aggregate queries. Showing a metric never grants access to its linked destination.

## Client layout configuration

Global `config/modules.php` may define `dashboard.metrics` defaults. Dashboard preset overrides live under `dashboard.presets.<preset>.metrics`. The final selected-client override is `dashboard.metrics` in `client/<client-key>/config/client.php`.

```php
'dashboard' => [
    'metrics' => [
        'keys' => ['example.total', 'example.count'],
        'max' => 4,
        'priorities' => ['example.total' => 200],
    ],
],
```

The example keys are placeholders supplied by a hypothetical module, not built-in registrations. Key lists replace earlier lists at each configuration layer. Higher priority wins; equal priorities preserve the client-configured order. The dashboard renders at most four metrics.

If none of the selected providers resolves, the existing generic count-card row remains unchanged. If one or more metrics resolve, they replace only that summary row, not the actionable work/context panels below it.

Do not configure a metric key until the owning enabled module has registered the corresponding provider. A future settings UI must operate on the same layout semantics rather than introducing a second competing configuration model.

## Local verification

1. With no metric keys selected, confirm the existing count cards and work/context panels still render.
2. With generic test providers selected, confirm count/currency formatting, period, link, client priority, and ordering.
3. Disable the test provider's module or remove the provider and confirm the metric is excluded without querying disabled code.
4. Confirm the dashboard still uses the owning module's access checks and client configuration lives in the selected-client repository.