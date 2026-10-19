<?php

namespace Splicewire\Beam\Tenancy\Doctor;

use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantHost;
use Splicewire\Beam\Tenancy\TenantSlug;
use Throwable;

/**
 * Lists the tenants whose slug or domain bindings fail the slug grammar ({@see TenantSlug}), or that no safe Tenant
 * Host can be built for ({@see Tenant::routableHost()}).
 *
 * ## Why it exists beside the grammar
 * Validating every door stops NEW rows; it says nothing about rows already written. Before the grammar, a broker could
 * provision `brk-x@evil.example` or `brk-x.evil.example`, and `CreateTenantDomain` bound that slug as the tenant's
 * domain. {@see Tenant::primaryHost()} no longer links either anywhere, but the rows stay until someone looks at them —
 * so this is the look.
 *
 * ## ⚠️ READ-ONLY and ADVISORY
 * It never writes, and it never fails a run: which rows a host holds is a fact about that host's data, not a defect
 * the package's author could have prevented (the estate's audit rule — see {@see MachineIdentityOnMembershipPivotAudit}).
 * A row is reported with what is wrong and what the links do instead; renaming a tenant moves its schema, its search
 * indexes and its subdomain, and that is an operator's decision, not a doctor's.
 *
 * Over ZERO tenants, or when the tables cannot be read, it is inconclusive — "nothing here", not "measured clean". One
 * row that throws is reported as that row, never allowed to end the sweep early with a list that reads complete.
 */
class TenantHostAudit implements DoctorAudit
{
    public const CHECK = 'tenancy.tenant-hosts';

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        try {
            ['checked' => $checked, 'defects' => $defects] = $this->measure();
        } catch (Throwable $e) {
            return [Finding::inconclusive(self::CHECK, 'Could not read the tenants and their domains: '.$e->getMessage())];
        }

        if ($checked === 0) {
            return [Finding::inconclusive(self::CHECK, 'No tenant on this host — nothing to check (not "measured clean").')];
        }

        if ($defects === []) {
            return [Finding::pass(self::CHECK, "{$checked} tenant(s): every slug is one lowercase DNS label, every binding is one too or a verified custom domain, and every Tenant Host is built from them.")];
        }

        $rows = [];
        foreach ($defects as $key => $problems) {
            $rows[] = $key.': '.implode('; ', $problems);
        }

        return [Finding::warn(
            self::CHECK,
            count($defects)." of {$checked} tenant(s) fail the tenant slug rule (one lowercase DNS label, at most "
            .TenantSlug::MAX_LENGTH.' characters) — '.implode(' | ', $rows)
            .'. Nothing was changed: renaming a tenant moves its schema, search indexes and subdomain.',
        )];
    }

    /**
     * The sweep itself, separate from the verdict so a caller (or a test) can read every row's problems.
     *
     * @return array{checked: int, defects: array<string, list<string>>}
     */
    public function measure(): array
    {
        $tenants = Tenant::query()->with('domains')->orderBy('id')->get();
        $defects = [];

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            $key = (string) $tenant->getTenantKey();

            try {
                $problems = $this->problems($tenant);
            } catch (Throwable $e) {
                $problems = ['could not be checked: '.$e->getMessage()];
            }

            if ($problems !== []) {
                $defects[$key] = $problems;
            }
        }

        return ['checked' => $tenants->count(), 'defects' => $defects];
    }

    /**
     * @return list<string>
     */
    protected function problems(Tenant $tenant): array
    {
        $problems = [];
        $slug = (string) $tenant->slug;
        $key = (string) $tenant->getTenantKey();

        if ($slug === '') {
            $problems[] = 'has no slug';
        } elseif (! TenantSlug::isValid($slug)) {
            $problems[] = TenantSlug::isValid(TenantSlug::normalize($slug))
                ? "slug '{$slug}' is not lowercase"
                : "slug '{$slug}' is not one lowercase DNS label";
        }

        if ($key !== $slug && ! TenantSlug::isValid($key)) {
            $problems[] = "id '{$key}' is not one lowercase DNS label";
        }

        /** @var Model $binding */
        foreach ($tenant->domains as $binding) {
            $domain = (string) $binding->getAttribute('domain');

            if (str_contains($domain, '.')) {
                if (! $tenant->domainIsVerified($binding)) {
                    $problems[] = "binding '{$domain}' is a custom domain nothing has verified, so it is never linked";
                } elseif (! TenantHost::isName(strtolower($domain))) {
                    $problems[] = "binding '{$domain}' is verified but is not a host name, so it is never linked";
                }

                continue;
            }

            if (! TenantSlug::isValid($domain)) {
                $problems[] = "binding '{$domain}' is not one lowercase DNS label";
            }
        }

        if ($problems === []) {
            return [];
        }

        $host = $tenant->routableHost();
        $problems[] = $host === null
            ? 'no safe host can be built — its links point at '.TenantHost::UNROUTABLE
            : "still linked at {$host}";

        return $problems;
    }
}
