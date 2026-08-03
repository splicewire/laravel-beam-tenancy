# Bounded context

This package owns the generic notion of a **designated system tenant**: in a
stancl multi-tenant app there is exactly one tenant, identified by a configurable
slug + role marker and seedable as a default, that the platform treats as its own
"system" tenant. The context is deliberately narrow — it knows how to resolve,
stamp, and seed that tenant, but knows nothing about what any specific app's
system tenant actually *does* with that designation.

Because it owns the domain→tenant mapping, this package also binds the
**multi-domain sitemap base-URL resolver** (ADR-0166 §3): it `require`s
`laravel-beam-sitemap` for the `SitemapBaseUrlResolver` port and re-binds it to
`TenantSitemapBaseUrlResolver`, which returns the active tenant's domain
(`Tenant::primaryHost()`) when a tenant context is initialized, and falls back to
beam-sitemap's `config('app.url')` default when tenancy is absent. Cross-tenant
sitemap aggregation is deferred to tower (ADR-0166 §5), not built here.
