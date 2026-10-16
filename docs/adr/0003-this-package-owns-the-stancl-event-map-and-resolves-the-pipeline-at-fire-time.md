# 0003 — This package owns the stancl event map, and the pipeline resolves at fire time

Status: accepted (2026-10-16, owner; host-binding-seam ticket 02, from architecture-pass finding
B6-2)

## Context

The TenantCreated→JobPipeline dispatch — the wiring that makes a saved tenant row actually
provision — existed exactly once in the estate: the flagship's 299-line
`app/Providers/TenancyServiceProvider.php`, with zero `App\` imports (measured 2026-10-16; the event
map, bootEvents, middleware priority and tenant-route mounting were all package-vocabulary). The
tower starter — the self-hosted multi-tenant product — shipped no copy, so its tenants had no
pipeline subscriber: `config/tower/provisioning.php`'s own header records provisioning dying "at the
third step of twelve" there, and `docs/agents/traps/tenancy.md` warns that a saved tenant row does
not prove a queued TenantCreated pipeline ran. The step LIST had already been registry-fied
(`TenantProvisioningStepRegistry`, `OnKeyDuplicate::Supersede`) precisely to end hand-copies; the
dispatch that reads it stayed host-authored prose.

## Decision

- `BeamTenancyServiceProvider::bootStanclEventMap()` wires the map: TenantCreated → the registered
  pipeline, TenantDeleted → DeleteDatabase, DatabaseCreated/Migrated → `markProvisioning` timeline
  stamps (method_exists-guarded), TenancyInitialized/Ended → stancl's Bootstrap/Revert listeners,
  SyncedResourceSaved → `UpdateSyncedResource` (container-resolved, so the machine-identity rebind
  applies). `bootHostRouting()` owns the tenancy middleware priority and the file_exists-guarded
  `routes/tenant.php` group + the discovery re-run (the api-surface-coherence 105 lesson moves with
  the code).
- **The pipeline resolves at event-fire time**, from `beam.tenancy.provisioning.pipeline` (default
  `default`), never at boot: the flagship read `stepsFor()` once while building its listener array,
  which made later registrations and Supersede overrides invisible. No pipeline registered → the
  listener no-ops; this package still ships NO default pipeline (the provenance rule on
  `registerProvisioningSteps()` stands — the tier that owns the step classes registers them; tower
  registers its 14 CANONICAL_STEPS in packageBooted).
- Escape hatches, config lane: `beam.tenancy.wire_stancl_events` and
  `beam.tenancy.wire_host_routing`, both default on. A host that hand-wires its own map sets them
  false instead of double-dispatching — standwell is the live case (five app-local step classes
  sharing tower's names); its stale lock means nothing changes there until it updates, and this ADR
  is the note it updates against. The package harness sets both false: a testbench app is not a
  stancl host.
- The flagship adopts: its provider shrinks to genuine host policy (per-tenant
  `storageToConfigMap`, the central-migration path rewrites, the `circuits.discovery` sanctum line)
  in the same change-set — the hard-move rule, no deprecation window.

## Consequences

- `composer setup` on the tower starter yields a host whose tenants actually provision; ADR-0008's
  Hosted-site provisioning steps ride the same pipeline.
- Host-policy middleware on discovered routes stays host-side (a booted() hook registered after the
  package's — host providers boot later by construction).
- The registry's "What this replaces" census gains a current-state line; the flagship row of that
  census is history, not description.
