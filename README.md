# Splicewire Satellite Multi-Tenancy

A tiny resolver for the one **designated system tenant** of a
[stancl/tenancy](https://tenancyforlaravel.com/) multi-tenant app. The system
tenant is identified by a **configurable slug + role marker** and can be seeded
as a default with a single call.

## Scope (v1)

**Resolver-only.** This release does exactly one thing: given a Tenant model, it
resolves / stamps / seeds the single tenant that carries the configured system
role. It knows nothing about what a given app's system tenant *does*.

### Roadmap (explicitly out of v1 scope)

The following are planned as **later modules of this same package**, not part of
v1:

- Base stancl tenancy wiring.
- ADR-0043 brokering extraction.

## Install

```bash
composer require splicewire/laravel-satellite-multi-tenancy
```

The service provider is auto-discovered. Publish the config if you want to
override the defaults:

```bash
php artisan vendor:publish --tag=splicewire-multi_tenancy-config
```

This writes `config/splicewire/multi_tenancy.php`:

```php
return [
    'system_tenant' => [
        'slug' => env('SPLICEWIRE_SYSTEM_TENANT_SLUG', 'system'),
        'name' => 'System',
        'role' => 'system',
    ],
];
```

Values are exposed at `config('splicewire.multi_tenancy.system_tenant.{slug,name,role}')`.

## Usage

Add the trait to your stancl Tenant model (it uses VirtualColumn, so `role`
folds into the `data` JSON column):

```php
use Splicewire\SatelliteMultiTenancy\Concerns\DesignatedSystemTenant;
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
