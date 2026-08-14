> You are in **splicewire/laravel-beam-tenancy** — the multi-tenancy substrate of the beam stack.

The `stancl/tenancy` arm of a beam host: the central `Tenant` model and its seat/invitation
primitives, the Postgres schema-per-tenant storage managers, the tenancy bootstrappers that re-scope
ambient state on every tenant switch, and the provisioning-destination seam behind isolated tenant
databases. Config lives at `config('beam.tenancy.*')`; migrations publish rather than load
(`tenants`, `domains`, `tenant_users`, `tenant_invitations`).

What it carries today:

- **`Tenant`** — extends stancl's base tenant with database + domain support. Provisioning status and
  run id, owner email, plan slug and scaffold packs, suspension, synthetic marker, isolated-database
  state, and the ADR-0043 `parent_tenant_id` broker pointer. Most of these ride the `data` column.
- **Seat + invite primitives** — `TenantUser`, `TenantInvitation`, their `Data` projections, and the
  member controller. `CentralActivityLog` / `CentralStatus` for central-side records.
- **Storage** — `HybridPostgresTenantDatabaseManager` and `PostgreSQLSchemaManager` (shared-cluster
  schema-per-tenant), plus `Destinations/*` for an Isolated Database on Laravel Cloud or a
  customer-supplied host.
- **Bootstrappers** — `PermissionsTenancyBootstrapper` (re-scopes spatie permissions to the active
  tenant and flushes the registrar cache) and `CircuitTenancyBootstrapper`.
- **Ports** — DNS, edge, capability-overlay and domain-provision contracts, with null-object
  defaults; a sitemap base-URL resolver that returns the active tenant's domain; a doctor audit for
  the migration wiring.
- **The designated system tenant** — the original v1 surface, now one concern among many:
  `Concerns/DesignatedSystemTenant` resolves, stamps and seeds the single tenant carrying the
  configured system role.

## Dependency direction — the constraint that shapes this package

**`splicewire/laravel-beam-commerce` requires this package.** Nothing here may name a
`Splicewire\Beam\Commerce\*` symbol: doing so closes a dependency cycle. Plan, bill, subscription and
entitlement concerns belong *above* this package, reached through a config/contract seam with a
null-object default — never by importing the class.

This is why tower's enriched tenants admin resource (plan ⊕ bill ⊕ entitlement enrichment) cannot
descend into this package, and why any tenant projection shipped from here stays commerce-free.

`splicewire/laravel-beam-accounts` is a genuine, acyclic dependency — `Tenant` composes its
`HasMembers`, `TeamContract` and `Role`.

> One known outstanding violation: `Tenant::billingAccount()` still does a `morphOne` on
> `Beam\Commerce\BillingAccount`, and the accounts edge is still undeclared in `composer.json`. Both
> are being cleared — see the `particle-identity-resources` ticket 01 in this repo's scratch slice.
> Don't add new upward references in the meantime.

## Not in this package

**ADR-0043 brokering.** A satellite provisioning child tenants per customer and reselling the
isolated workspace, with billing rolled up via `parent_tenant_id`, is a **satellite / paid** concern
owned by **entreport**. ADR-0043 declares it *"an entitlement + service identity + parent pointer +
async + a typed client resource, **not a new tenancy substrate**"* — spread across the satellite core
(`ProvisionsCustomerTenants`), beam-commerce (billing roll-up), and the app. This package is the
**beam / free** substrate; folding a moat concern into it violates the beam/satellite seam
(ADR-0082/0092). Multi-tenancy (a site *being* multi-tenant) and brokering (reselling child tenants)
are orthogonal — a broker satellite need not itself be multi-tenant. Keep them apart. The
`parent_tenant_id` column lives here; the brokering behaviour does not.

**Cross-tenant aggregation** is deferred to tower (ADR-0166 §5), not bound here.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
