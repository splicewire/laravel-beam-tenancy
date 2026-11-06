# 0005 — Whether reporting counts synthetic tenants is a runtime toggle

Status: accepted (2026-11-05; exec-board ticket 07, under the owner's "Accept all recommendations" ruling on the
exec-board plan, D6)

Wayfinding: `.scratch/splicewire/splicewire-ecosystem/exec-board/tickets/07-reporting-toggle.md`.
Supersedes in part: `splicewire/splicewire-app docs/adr/0026-synthetic-tenants-via-scenario-replay.md` (the
`config('synthetic.exclude_from_reporting')` setting and the cache-flippable runtime switch it promised).

## Context

A synthetic tenant is a `Tenant` whose `data->synthetic` is true, with `synthetic()` / `notSynthetic()` scopes on
this package's model. Flagship ADR-0026 said reads include synthetic tenants by default and financial-truth reads drop
them "only when `config('synthetic.exclude_from_reporting')` is on, itself backed by a cache-flippable runtime setting
so an operator can toggle it remotely without a redeploy the day real revenue arrives". The flagship config key
existed, but nothing read it, and the runtime switch was never built. A board (`splicewire/laravel-beam-exec`) now
needs the answer on every request, at any Beam host.

## Decision

1. **The toggle is this package's:** `Synthetic\SyntheticReporting::excludes()` = a cache override ??
   `beam.tenancy.synthetic.exclude_from_reporting` (env `SYNTHETIC_EXCLUDE_FROM_REPORTING`, the flagship's name,
   default false).
2. **`Tenant::scopeReportable()`** applies `notSynthetic()` only while `excludes()` is true. With the toggle off,
   `reportable()` is every tenant.
3. **The override is flipped by one of two doors, and both are logged:** the declared op
   `Synthetic\Ops\SetSyntheticReporting` (`tenants/synthetic-reporting`, a Write gated on `os.operate`; the host mounts
   it) and `splicewire:beam:tenancy:synthetic-reporting [--exclude|--include|--clear]`. Both go through
   `set(?bool, ?causer)`, which writes a `CentralActivityLog` row (`synthetic-reporting`, before and after, causer).
   `set(null)` hands back to config.
4. **The override is read from a named store, never the proxied cache.** stancl's tenant cache tags proxied calls with
   the tenant key, so a proxied read from inside a tenant would miss a central override; `cache_store` names the store
   (null = the default). An explicit include beats an exclude in config.

## Consequences

- `cache:clear` clears the override, and config answers again; the config comment and README say so.
- The exec board's default lens follows this switch: Truth while `excludes()` is true, Live otherwise
  (`splicewire/laravel-beam-exec docs/adr/0004-a-lens-chooses-the-board-source-and-a-synthesized-or-projected-figure-is-always-watermarked.md`).
- The flagship deleted its own `config/synthetic.php` (exec-board 15); a host that set the env keeps its value.
