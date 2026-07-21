# Bounded context

This package owns the generic notion of a **designated system tenant**: in a
stancl multi-tenant app there is exactly one tenant, identified by a configurable
slug + role marker and seedable as a default, that the platform treats as its own
"system" tenant. The context is deliberately narrow — it knows how to resolve,
stamp, and seed that tenant, but knows nothing about what any specific app's
system tenant actually *does* with that designation.
