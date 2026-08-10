> You are in **splicewire/laravel-beam-tenancy** — a resolver for the one designated system tenant.

A Laravel package providing a tiny resolver for the single **system tenant** of a
`stancl/tenancy` multi-tenant app. The system tenant is identified by a configurable slug + role
marker and can be seeded as a default with a single call. Resolver-only in v1: it resolves,
stamps, and seeds the system-role tenant, and knows nothing about what a given app's system
tenant does.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
