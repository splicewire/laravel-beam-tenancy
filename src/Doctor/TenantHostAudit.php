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
 *
 * ## ⚠️ Everything it prints is escaped ({@see self::printable()})
 * The rows it reports are the ones the old slug rule let in — and that rule accepted ANY 56-character string. The
 * doctor writes a finding's detail verbatim, so a slug of `x<ESC>[2J<LF>[PASS] forged` would clear the operator's
 * screen and print a clean line of its own over the finding meant to report it: the audit built to find the planted row
 * would be the thing that hid it. So a slug, an id, a domain, a host and a failure's message reach a finding only
 * through {@see self::printable()}, and {@see self::measure()} hands its callers the same escaped text.
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
            return [Finding::inconclusive(self::CHECK, 'Could not read the tenants and their domains: '.self::printable($e->getMessage()))];
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
     * The sweep itself, separate from the verdict so a caller (or a test) can read every row's problems. The keys and
     * the problems are PRINTABLE text ({@see self::printable()}), not the stored values: a caller that prints them is
     * as safe as this audit's own verdict. (Escaping is one-to-one, so two different ids never share a key.)
     *
     * @return array{checked: int, defects: array<string, list<string>>}
     */
    public function measure(): array
    {
        $tenants = Tenant::query()->with('domains')->orderBy('id')->get();
        $defects = [];

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            $key = self::printable((string) $tenant->getTenantKey());

            try {
                $problems = $this->problems($tenant);
            } catch (Throwable $e) {
                $problems = ['could not be checked: '.self::printable($e->getMessage())];
            }

            if ($problems !== []) {
                $defects[$key] = $problems;
            }
        }

        return ['checked' => $tenants->count(), 'defects' => $defects];
    }

    /**
     * @return list<string> printable text — every stored value in it has been through {@see self::printable()}
     */
    protected function problems(Tenant $tenant): array
    {
        $problems = [];
        $slug = (string) $tenant->slug;
        $key = (string) $tenant->getTenantKey();

        if ($slug === '') {
            $problems[] = 'has no slug';
        } elseif (! TenantSlug::isValid($slug)) {
            $shown = self::printable($slug);
            $problems[] = TenantSlug::isValid(TenantSlug::normalize($slug))
                ? "slug '{$shown}' is not lowercase"
                : "slug '{$shown}' is not one lowercase DNS label";
        }

        if ($key !== $slug && ! TenantSlug::isValid($key)) {
            $problems[] = "id '".self::printable($key)."' is not one lowercase DNS label";
        }

        /** @var Model $binding */
        foreach ($tenant->domains as $binding) {
            $domain = (string) $binding->getAttribute('domain');
            $shown = self::printable($domain);

            if (str_contains($domain, '.')) {
                if (! $tenant->domainIsVerified($binding)) {
                    $problems[] = "binding '{$shown}' is a custom domain nothing has verified, so it is never linked";
                } elseif (! TenantHost::isName(strtolower($domain))) {
                    $problems[] = "binding '{$shown}' is verified but is not a host name, so it is never linked";
                }

                continue;
            }

            if (! TenantSlug::isValid($domain)) {
                $problems[] = "binding '{$shown}' is not one lowercase DNS label";
            }
        }

        if ($problems === []) {
            return [];
        }

        $host = $tenant->routableHost();
        $problems[] = $host === null
            ? 'no safe host can be built — its links point at '.TenantHost::UNROUTABLE
            : 'still linked at '.self::printable($host);

        return $problems;
    }

    /**
     * A stored value as it may be PRINTED: printable ASCII stays as it is, and every other character is spelled out
     * — `\e`, `\n`, `\r`, `\t` for the four that have a short name, `\xNN` for any other control byte or a byte that
     * is not UTF-8, `\u{NNNN}` for any other character — and so is the backslash itself (`\\`), which keeps the
     * escaping one-to-one: a stored `\n` (backslash, n) and a stored newline never print the same.
     *
     * Wider than "control characters" on purpose. Every value this audit reads is supposed to be ASCII
     * ({@see TenantSlug}), so a character outside printable ASCII is itself part of the finding, and the escape is
     * what shows it: the 8-bit CSI (U+009B) that some terminals obey, a right-to-left override that reorders what
     * follows it, a line separator, a Cyrillic `а` standing in for a Latin one. None of those is visible, or
     * distinguishable, when printed raw.
     */
    public static function printable(string $value): string
    {
        // Matches by character when the value is UTF-8, by byte when it is not (preg refuses invalid UTF-8 under /u).
        $pattern = preg_match('//u', $value) === 1 ? '/[^\x20-\x5B\x5D-\x7E]/u' : '/[^\x20-\x5B\x5D-\x7E]/';

        return (string) preg_replace_callback(
            $pattern,
            static fn (array $match): string => match (true) {
                $match[0] === '\\' => '\\\\',
                $match[0] === "\e" => '\e',
                $match[0] === "\n" => '\n',
                $match[0] === "\r" => '\r',
                $match[0] === "\t" => '\t',
                strlen($match[0]) === 1 => sprintf('\x%02X', ord($match[0])),
                default => sprintf('\u{%04X}', mb_ord($match[0], 'UTF-8')),
            },
            $value,
        );
    }
}
