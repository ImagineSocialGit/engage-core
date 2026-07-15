<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contact Status Presets Template
    |--------------------------------------------------------------------------
    |
    | Example module-first file paths:
    |
    | Core-owned generic statuses:
    | config/presets/modules/core/contact-statuses.php
    |
    | Module-owned statuses:
    | config/presets/modules/{contributor-module}/contact-statuses.php
    |
    | Client-owned statuses:
    | client/{client-key}/config/presets/modules/client/contact-statuses.php
    |
    | Core owns the ContactStatus model and persistence lifecycle.
    |
    | Preset contributors may contribute ContactStatus groups and definitions:
    |
    | Core
    |     owns generic reusable lifecycle statuses.
    |
    | Feature module
    |     owns statuses specific to that module's domain.
    |
    | Client
    |     may optionally contribute client-specific or vertical-specific lifecycle
    |     statuses when the generic Core set is not sufficient.
    |
    | A client does not need a ContactStatus contribution file merely for symmetry.
    | If Core and module-owned groups already provide the intended lifecycle, the
    | client package may select those groups directly.
    |
    | A contributed group may reference a definition owned by another contributor.
    | For example, a client-specific lifecycle group may include Core-owned `new`.
    | The client must not redefine the same `new` definition key.
    |
    | ContactStatus is available for manual CRM adjustment even when Workflow,
    | FlowRoutes, Campaigns, Webinars, or Tasks are disabled.
    |
    | Internal keys should remain stable and deliberate. Generic Core keys should
    | remain contact-neutral. Client-facing names and descriptions may use the
    | configured industry noun when the contribution is deliberately client- or
    | vertical-specific.
    |
    | Do not split statuses into manual-only or automation-only categories by
    | default.
    |
    | If a manual status change will trigger selected FlowRoute automation, the CRM
    | UI should warn the operator before applying the change.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Current generic Core lifecycle
    |--------------------------------------------------------------------------
    |
    | The current generic Core ContactStatus group is:
    |
    | default
    |     new
    |     active
    |     inactive
    |
    | `Engaged` is not part of the current generic Core lifecycle set.
    |
    | `Requires Action` is not part of the current generic Core lifecycle set.
    | Attention/work state should not be modeled as a generic lifecycle status
    | merely for symmetry.
    |
    | Module-specific states, such as Webinar registration/outcome statuses, belong
    | to their owning module's preset contribution.
    |
    | Client-specific lifecycle states belong to the optional client contributor.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sync/customization expectations
    |--------------------------------------------------------------------------
    |
    | ContactStatus rows are DB-owned.
    |
    | Normal sync updates non-customized rows and preserves customized rows.
    |
    | Explicit force sync may overwrite customized rows and clears:
    |
    | is_customized
    | customized_at
    |
    | Keep first-class status values in first-class fields. Do not duplicate
    | description, category, color, source_version, or other first-class values
    | inside meta.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Validation expectations
    |--------------------------------------------------------------------------
    |
    | Shared preset-composition validation owns package/group/definition structure,
    | including:
    |
    | - missing selected groups;
    | - duplicate contributed group keys;
    | - duplicate contributed definition keys;
    | - group references to missing definitions;
    | - selected groups contributed by modules unavailable to the package.
    |
    | Core owns semantic validation of selected ContactStatus definitions.
    |
    | Stable internal keys remain contact-neutral where the definition itself is
    | generic. Client-facing labels may use the configured industry noun without
    | changing the canonical Contact model or runtime identity.
    |
    | FlowRoutes change-status points may reference these stable keys and should be
    | validated against the selected preset package's available ContactStatus
    | definitions before sync/client handoff.
    |
    */

    'groups' => [
        'default' => [
            'new',
            'active',
            'inactive',
        ],
    ],

    'definitions' => [
        'new' => [
            'key' => 'new',
            'name' => 'New',
            'description' => 'New contact who has not yet entered an active follow-up or working process.',
            'category' => 'general',
            'sort_order' => 10,
            'is_active' => true,
            'source_version' => 1,
        ],

        'active' => [
            'key' => 'active',
            'name' => 'Active',
            'description' => 'Contact currently involved in an active follow-up, service, sales, or engagement process.',
            'category' => 'general',
            'sort_order' => 20,
            'is_active' => true,
            'source_version' => 1,
        ],

        'inactive' => [
            'key' => 'inactive',
            'name' => 'Inactive',
            'description' => 'Contact is not currently active or progressing through a current workflow.',
            'category' => 'general',
            'sort_order' => 90,
            'is_active' => true,
            'source_version' => 1,
        ],
    ],

];