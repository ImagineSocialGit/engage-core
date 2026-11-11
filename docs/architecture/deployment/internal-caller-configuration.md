# Client-agnostic internal caller configuration

Core owns a shared selected-client metadata registry under
`INTERNAL_PLATFORM_CALLERS`. Enabled packages declare supported caller purposes
and peer platforms through `CoreCredentialCallerContributor`, an optional
extension of their existing runtime contributor. Infrastructure selects the
actual source/destination clients, independently observes their origins and
obtains a read-only package-owned target through
`engage:credential-caller:inspect`.

No client name, customer domain, repository path, hand-entered caller ID or
special-case script is part of this protocol. The caller ID is the selected
source-client key. Credentials remain independently scoped by customer,
environment, source/destination clients, purpose and caller in Infrastructure's
existing credential identity contract.

## Registry format

The value is canonical URL-safe base64-encoded JSON, without padding. Encoding
is only a dotenv transport format; it is not encryption or authorization.
The document contains schema version 1, the Core client key, environment, and a
sorted list of caller records. Each record contains only purpose, source
platform, source client, derived caller ID, and source origin.

There is no signing secret, arbitrary environment key, provider credential,
approval flag, executable configuration, or operator-chosen target path in a
record. Unknown fields, duplicate records/JSON names, noncanonical encodings,
cross-client/environment copies, malformed origins and conflicting assignments
are rejected. Storage is bounded to 128 callers and 64 KiB encoded metadata.
No database tables, JSON columns, events, audit rows or payload copies are added.

Adding a different caller preserves existing callers. Repeating the same
assignment is idempotent. Changing the origin of an existing caller conflicts;
it is not treated as permission to overwrite or rotate a credential.

## Ownership and operation flow

The Core module contributes the registry's non-secret client-scoped environment
definition through the existing dynamic catalog. Static reference templates do
not become a second list of generated caller state.

The read-only command validates actual selected client/environment identity,
current plan integrity, a unique registered owner capability, the exact
Core-owned non-secret environment definition and selected-client scope. The
owner validates the proposed caller against its existing effective configuration
before the command can return a target. It returns the exact desired metadata value while keeping writes and
credential equality false. Infrastructure must bind this target to the same
normal approved operation that will generate/install the credential, compare
the current selected-client file before writing, preserve unrelated bytes and
callers, then rerun platform discovery. A report alone grants no permission.

Packages interpret only their own purpose and supported peer platform. They
derive their own secret bindings, contribute required environment definitions,
and use their real runtime consumers. Core contains no Donations-specific caller
setup branch. Future packages use the same registry and contributor interface.

Core supplies the registry and target contract. Infrastructure B36/B37 uses
those contracts through its local deployment command; applying Core source
creates no caller state by itself. The selected client's installed packages
must also contain their matching caller contributor/consumer support.

## Source and installed-package prerequisites

The console command is registered explicitly by Core's AppServiceProvider.
CoreCredentialCallerCommandTest verifies that actual registration; registry
unit tests alone do not prove that the CLI command is installed. The early and
strict loader passes, Core metadata definition, registry/interface and command
are one integration: installing only the command file is incomplete.

Composer-installed client packages are separate from development checkouts.
For Donations, its loaded DonationsCredentialRuntimeContributor must implement
CoreCredentialCallerContributor, and DonationsModuleServiceProvider must consume
the generic registry. Updating a development checkout does not update a locked
client vendor installation. Commit/push intended package source changes, then
use a targeted Composer update in the selected client and commit its lockfile.
Do not edit generated vendor files; their source belongs to the owning package.

The runtime .env permission policy remains Infrastructure B37's group-preserving
0640 policy; controller-only secrets remain 0600. This Core repair performs no
permission changes and does not replace any Infrastructure or SEO repair.

## Bootstrap ordering

The early client environment pass runs before service providers have registered
dynamic definitions. It loads known client-owned values, rejects root-owned
values immediately, and holds unknown values out of the process environment.
It also clears inherited values for those deferred names, preventing fallback
to stale process/root values.

Before any provider boot method runs, a mandatory strict pass uses the complete
registered catalog. Newly declared dynamic keys can now load. An unknown name
that no enabled owner declared still aborts bootstrap. Cached runtimes undergo
the strict pass too. Testing keeps the existing isolated-host bootstrap policy.

Owners whose dynamic bindings depend on selected configuration must use their
runtime credential resolver/private selected-client reader, rather than trying
to read an unregistered dynamic secret through env() while configuration is
still being constructed. The shared metadata reader uses the existing private
environment repository and validates client/environment scope directly.

## Boundaries

The registry is runtime configuration, not an approval ledger. It never issues,
compares, stores or rotates secrets. Normal deployment approval still governs
its installation. DNS reporting, HTTP checks and service lifecycle stay outside
this caller-configuration contract.