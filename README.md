# splicewire/laravel-beam-tenancy

The multi-tenancy substrate of the beam stack — the [stancl/tenancy](https://tenancyforlaravel.com/)
arm of a beam host.

It ships the central `Tenant` model and its seat/invitation primitives, the Postgres
schema-per-tenant storage managers, the bootstrappers that re-scope ambient state on every tenant
switch, and the provisioning-destination seam behind isolated tenant databases. It also still
carries the **designated system tenant** resolver it started life as.

## What's in the box

- **`Tenant`** — extends stancl's base tenant with database + domain support. Tracks provisioning
  status and run id, owner email, plan slug and scaffold packs, suspension, a synthetic marker,
  isolated-database state, and the ADR-0043 `parent_tenant_id` broker pointer. Most of these ride
  stancl's `data` JSON column via VirtualColumn.
- **Seats and invitations** — `TenantUser`, `TenantInvitation`, their `Data` projections, and a
  member controller. `CentralActivityLog` for the central-connection audit trail (the status
  timeline reads off it). The spatie status model is NOT named here — it is the host's, via
  `model-status.status_model`.
- **Storage** — `HybridPostgresTenantDatabaseManager` and `PostgreSQLSchemaManager` for
  shared-cluster schema-per-tenant, plus provisioning destinations for an Isolated Database on
  Laravel Cloud or a customer-supplied host.
- **Bootstrappers** — `PermissionsTenancyBootstrapper` re-scopes spatie permissions to the active
  tenant and flushes the registrar cache on every switch; `CircuitTenancyBootstrapper` does the
  equivalent for circuits.
- **Ports** — DNS, edge, capability-overlay and domain-provision contracts, each with a null-object
  default so a host binds only what it needs. A sitemap base-URL resolver returns the active
  tenant's domain, falling back to the configured default when tenancy is absent.
- **Doctor** — an audit for the migration wiring.

## Install

```bash
composer require splicewire/laravel-beam-tenancy
```

The service provider is auto-discovered. Publish the config and migrations:

```bash
php artisan vendor:publish --tag=laravel-beam-tenancy-config
php artisan vendor:publish --tag=laravel-beam-tenancy-migrations
```

Config publishes to `config/beam/tenancy.php` and is reached at `config('beam.tenancy.*')` — beam
config keys use the product word, not the vendor (ADR-0092).

Migrations are **publish-only**, never loaded from the vendor source: publishing gives you
`tenants`, `domains`, `tenant_users` and `tenant_invitations`, in that order (the order matters —
`create_tenants_table` must sort ahead of the ALTERs that follow it).

```php
return [
    'system_tenant' => [
        'slug' => env('SPLICEWIRE_SYSTEM_TENANT_SLUG', 'system'),
        'name' => 'System',
        'role' => 'system',
    ],
];
```

## The designated system tenant

One tenant in a multi-tenant app can be marked as *the* system tenant, identified by a configurable
slug + role marker and seeded idempotently with a single call. The package's own `Tenant` composes
the trait; a host with its own tenant model can use it directly:

```php
use Splicewire\Beam\Tenancy\Concerns\DesignatedSystemTenant;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    use DesignatedSystemTenant;
}
```

Then:

```php
Tenant::provisionSystem(['name' => 'System']); // idempotent seed
Tenant::system();                              // resolve, or null
$tenant->isSystem();                           // bool
$tenant->markAsSystem();                       // stamp the role
Tenant::systemSlug();                          // configured slug
Tenant::systemName();                          // configured name
Tenant::systemRole();                          // configured role marker
```

The resolver knows nothing about what a given app's system tenant *does* — it resolves, stamps and
seeds, and stops there.

## The demo tenant

`Database\Seeders\DemoTenantSeeder` seats `splicewire/laravel-beam-accounts`' demo roster —
`demo-owner`, `demo-admin`, `demo-member` — on a dedicated tenant (`beam-demo` by default), one
`tenant_users` seat per `Role`.

It exists because the two halves never met. beam-accounts provisions a role-differentiated demo
roster with **no** central spatie permissions, and seats it on a beam-accounts *team*; it cannot
seat it on a *tenant*, since beam-tenancy requires beam-accounts and not the reverse. So a host
could have a perfect role-less actor and no tenant for it to be an ordinary member of, and no
authorization regression test could be written against a tenant-scoped gate. After this seeder,
`manageInvitations` is allowed for the Admin seat and denied for the Member seat on the same tenant,
off nothing but seeded data.

```php
// Runs as part of the stack-wide seed, gated:
php artisan splicewire:beam:seed

// Or on its own:
php artisan db:seed --class="Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder"
```

```php
'demo' => [
    // The seed-manifest gate. Null resolves at boot to `! production`.
    'seed_tenant' => env('BEAM_TENANCY_SEED_DEMO_TENANT'),

    'tenant' => [
        'slug' => env('BEAM_TENANCY_DEMO_TENANT_SLUG', 'beam-demo'),
        'name' => 'Beam Demo',
    ],
],
```

Notes worth having before you change it:

- **The tenant is dedicated, not borrowed.** Seating into an existing tenant buys two live hazards:
  seats whose `role` is outside the `Role` enum (`service` rows exist in the estate, and
  `memberRole()` raises a `ValueError` on them), and hosts whose per-tenant `model_has_roles`
  is never read because the role models pin the central connection. The seeder never iterates seats
  it did not create, for the same reason.
- **`solo` is excluded.** It models the team-of-one *shape*, not a role; it has no business sharing
  a tenant with three others.
- **Idempotent.** Users are `firstOrCreate`d, seats go through `TeamContract::assignMember()`, and
  `accepted_at` is stamped only when null — a second run is row-for-row identical.
- **Never in production.** The gate defaults to on everywhere else, mirroring
  `beam.accounts.demo.seed_users`.

## Dependency direction

**`splicewire/laravel-beam-commerce` requires this package.** Nothing here may name a
`Splicewire\Beam\Commerce\*` symbol — doing so closes a dependency cycle. Plan, bill, subscription
and entitlement concerns live *above* this package and are reached through a config/contract seam
with a null-object default, never by importing the class.

`splicewire/laravel-beam-accounts` is a genuine, acyclic dependency: `Tenant` composes its
`HasMembers`, `TeamContract` and `Role`.

## Not in this package

> **ADR-0043 brokering.** Brokering (a satellite provisioning child tenants per customer and
> reselling the isolated workspace, billing rolled up via `parent_tenant_id`) is a **satellite /
> paid** concern owned by **entreport**, and ADR-0043 explicitly declares it is *"an entitlement +
> service identity + parent pointer + async + a typed client resource, **not a new tenancy
> substrate**"* — a cross-cutting concern spread across the satellite core
> (`ProvisionsCustomerTenants`), beam-commerce (billing roll-up), and the app (provisioning route +
> auth). This package is the **beam / free** substrate; folding a moat concern into it would violate
> the beam/satellite seam (ADR-0082/0092). Multi-tenancy (a site *being* multi-tenant) and brokering
> (reselling child tenants) are orthogonal — a broker satellite need not itself be multi-tenant.
> Keep them apart. The `parent_tenant_id` column lives here; the brokering behaviour does not.

**Cross-tenant aggregation** is deferred to tower (ADR-0166 §5), not bound here.
