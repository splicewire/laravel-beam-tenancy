# 0004 — The kernel tenancy bridge lives here, behind a soft edge

Status: accepted (2026-10-16, owner ruling at the host-binding-seam grilling: "build it into beam,
not just tower")

## Context

`rushing/laravel-saloon-kernel`'s in-process transport declares a `KernelContextBridge` port —
*when* to capture/restore context around a nested kernel dispatch — and `bindIf`s a
`NullContextBridge`. The only implementation that knows *what* to capture (the current tenant) was
flagship app code (`app/Splicewire/Loopback/TenancyContextBridge.php`, bound in the flagship's
AppServiceProvider), which a self-hosted install cannot get: tower and the tower starter bind
nothing, so any non-flagship host on the kernel transport silently dropped tenant context across
federation-loopback hops. Two residency candidates existed — tower (the transport's only code
consumer today; splicewire-recohere research 09 had nominated MOVE-TO-TOWER) and this package (the
mechanism is pure tenancy vocabulary: `tenancy()`, `tenant()`, initialize/end).

## Decision

The owner ruled residency by vocabulary, not by today's consumer count: **the bridge is tenancy
mechanism and lives in beam-tenancy** (`src/Loopback/TenancyContextBridge.php`), superseding the
recohere research nomination. The edge stays soft:

- the contract arrives via require-dev + suggest, never a hard composer require — registration is
  guarded by `interface_exists(KernelContextBridge::class)`, the licenser-shaped
  optional-integration pattern, so hosts without the transport pay nothing;
- the binding is a plain `bind()` over saloon-kernel's `bindIf` — the order-independent
  supersession lane: where tenancy is installed, dropping tenant context across a nested dispatch
  is a defect, not a default — with `beam.tenancy.bind_kernel_context_bridge` (default on) as the
  escape hatch;
- tower inherits transitively (it requires this package); `KernelLoopbackConnector` and the rest of
  the flagship's Loopback directory are dogfood-transport vocabulary (they extend the generated
  `SplicewireConnector`), stay host-side, and their residency belongs to the tower ticket's
  inventory, not here.

`restore()` stays a total inverse of `capture()`: a captured tenant re-initializes; a captured null
ends whatever the nested request left initialized.

## Consequences

- Federation loopback behaves identically on the flagship and a self-hosted install — one binding,
  zero host code (L3).
- The flagship deletes its copy and its AppServiceProvider bind in the same change-set; tower's
  docblock cites update to this FQCN.
- If a second kernel-transport consumer ever makes the bridge's *selection* vary per host, that is
  a new decision against this ADR, not a silent rebind.
