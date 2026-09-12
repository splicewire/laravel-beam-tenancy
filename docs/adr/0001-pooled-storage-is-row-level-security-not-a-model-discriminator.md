# ADR-0001 — Pooled storage is Postgres row-level security, not a model discriminator

Status: accepted (2026-09-12) · Scope: repo-local (`docs/conventions/adr-placement.md` — first ADR of
this package; promote to the runbook only when a second host runs pooled) · Map:
`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/laravel-beam-tenancy/pooled-storage/`

## Context

A tenant on this substrate has had two storage states: a schema of its own on the shared cluster (the
default) and an Isolated Database (a dedicated server, `tenant-database-upsell`). Both cost a schema,
a per-tenant migration pass and a catalog footprint whatever the tenant's size, so there was no base
tier many small tenants could share.

The runbook's isolation model (`~/Workspaces/splicewire-beam-runbook/references/multitenancy.md:25-31`
@ 7131ea6, measured 2026-09-12) forbids "a single-store `tenant_id`
model … isolation is the schema boundary, not a discriminator column", and package models carry no
`tenant_id` and no global scope. That rule is about the **application** discriminating rows, and it
stands: the estate's models must keep working unchanged across every storage state.

stancl/tenancy v4 ships a Postgres RLS mode. It is prior art, not an adoption target (spec §Adopt
versus build): unreleased at decision time (dev-master, PHP 8.4, Laravel 12+); it needs a foreign-key
path to `tenants` in every scoped table or a `BelongsToTenant` trait, which this estate's tenant-agnostic
shared migrations do not carry; it disables the database bootstrapper, so it cannot mix with schema
and isolated tenants; and its plain `SET` does not survive a reconnect.

## Decision

1. **A third storage state, Pooled**, derived by `Tenant::storage()` from markers — isolated wins,
   then the `pool` marker, else schema — never stored as a fourth marker. Vocabulary: a *storage
   state*; "isolation level" belongs to the satellite Org ladder (app ADR-0057), "tier" to billing.
2. **The database discriminates, not the model.** Every table in a pool schema carries
   `tenant_id text NOT NULL DEFAULT current_setting('app.tenant_id')` and a row-level-security policy
   `USING (tenant_id = current_setting('app.tenant_id', true)) WITH CHECK (same)`. Eloquent inserts
   never name the column; reads, updates and deletes are scoped by the policy; a missing setting
   reads zero rows and refuses inserts (fail-closed). Unique indexes are rewritten to lead with the
   column. Measured: pooled-storage ticket 01 evidence, 23/23.
3. **The connection carries the key.** The tenant's `tenancy_db_session_settings` internal rides
   stancl's `tenancy_db_*` merge into the tenant connection config as `session_settings`; a pgsql
   connector subclass (`rushing/laravel-postgres-rls`, bound at `db.connector.pgsql`) applies it on
   connect and on every reconnect. No global state; the tpetry Connection override coexists.
4. **The privilege boundary is a role.** Pooled tenant connections authenticate as a dedicated
   non-owner Postgres role (`beam.tenancy.pooled.rls_user`); a missing role is a hard stop, never a
   fallback to the owner. The owner runs pool migrations and is not subject to the policy unless
   `force_rls` is on, which then needs `BYPASSRLS` — ungrantable on managed Postgres (Cloud SQL
   grants no superuser), so `force_rls` defaults off. Borrowed from v4, with attribution.
5. **A pool is migrated once**, as the owner, by `splicewire:beam:tenancy:pools:migrate`: drop the
   policies, run the same paths `tenants:migrate` would, re-prepare (policy names carry a body hash
   so outdated ones are recreated; zombies dropped — also v4's idea), re-grant the role. stancl's
   `tenants:migrate` is extended through the container to skip pooled tenants by name and to never
   fan out on an empty list; `tenants:rollback` and `tenants:migrate-fresh` refuse a pooled tenant
   outright, because a rollback or wipe of the tenant connection is one of the whole pool. A data
   backfill therefore never runs inside one tenant's frame. Not guarded: a hand-run
   `migrate --database=<pool connection>` — an operator act outside every command this package owns.
6. **Deleting a pooled tenant deletes rows, inside its own frame**, so the policy scopes every
   `DELETE`; never as the owner and never with an application-written `WHERE`.
7. **Creation decides storage once** (`DecideStorage`: `requested_storage` → the
   `storage_resolver` seam → `default_for_new_tenants`), ahead of `CreateDatabase`, and is a no-op on
   a marked tenant. Isolated is never a creation outcome. Promotion pool → schema is a live-migration
   job in tower (map ticket 09); downgrade is out of scope with a named trigger.

## Consequences

- The runbook sentence needs one clause: the prohibition stays on an application discriminator; a
  storage-enforced pooled schema is a substrate state the model still does not see (map ticket 07
  amends it, and app ADR-0221 records the flagship's default).
- Accepted hazards of the base tier, documented in the rushing package's convention doc: foreign-key
  checks bypass RLS (a guessed id can be referenced); sequences are pool-global (ids reveal
  existence by gap — 81 serial columns in the shared set, ticket 01 census); backups are
  pool-granular.
- On a dev box every connection is a superuser (`postgres`, `root`), and superusers bypass RLS
  whatever FORCE says, so pooled isolation is **not exercised** unless a non-superuser role is
  used. The doctor audits (map tickets 03/06) exist for that reason.
- A transaction-mode pooler drops session settings. None is configured in the estate; the probe is
  repeated against the production endpoint at deploy (ticket 07).
