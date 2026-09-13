# ADR-0002 — A pool may live on another database server, and tenants move between pools

Status: accepted (2026-09-12) · Scope: repo-local · Extends ADR-0001 · Map:
`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/laravel-beam-tenancy/pooled-storage/` ticket 13

## Context

ADR-0001 put every pool on the central cluster. Pooling many small tenants is a scaling strategy, and
one server stops being enough at some size: pools need to spread across servers, and tenants need to
move between them without a schema tenant's per-tenant cost. The owner asked for this as an option in
this package only — no host adopts it yet.

## Decision

1. **A pool names its server in config**, `beam.tenancy.pooled.pools.<name>.connection`, a pgsql
   connection that is the owner for that server; unlisted pools stay central. An optional per-pool
   `rls_user` overrides the global non-owner role. `Pools\PoolRegistry` is the one place that answers
   "which server, which role" — the pool migrator, the connection hook, the scoped-delete guard, the role
   and direct-access commands, and the doctor audits all ask it.
2. **The tenant stores the connection name, not credentials**, as stancl's own `tenancy_db_connection`
   internal: stancl already builds a tenant connection from that template, so no new connection plumbing.
3. **Moving is copy → verify → flip → clear**, across two servers that never share a transaction
   (`Pools\TenantPoolMover`, `pools:move`). The copy runs on the target as the target pool's non-owner
   role with the tenant's key bound, so the policy stamps and scopes every row. The target pool is live for
   other tenants, so its foreign keys are not dropped: tables insert in savepoints and retry in passes,
   falling back to row-by-row. Only tables the RLS policy scopes are moved or deleted — a pool-level table
   a host excluded would otherwise be copied and deleted for every tenant — and the old rows are deleted
   only if the old pool still holds exactly what was copied (a queued job writing mid-move keeps them).
4. **Not wired into any host.** tower's pool-to-schema promotion reads through the central connection, so
   it refuses a tenant whose pool is on another server rather than cutting it over to an empty schema.

## Consequences

- A tenant in a remote pool has no central `public` schema behind its connection (the Isolated Database
  hazard, `Support\TenancyConnections`).
- Primary keys that are not tenant-scoped make a move fail when the target pool already holds the same id
  for another tenant (map ticket 11); the failure is before the flip.
- Choosing a pool for a NEW tenant is still `default_pool`; placement across servers is the host's job
  (move after provisioning, or set the default).
