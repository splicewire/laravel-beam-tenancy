<?php

namespace Splicewire\Beam\Tenancy;

use Closure;
use InvalidArgumentException;

/**
 * The declared grammar of a tenant slug: ONE lowercase DNS label.
 *
 * A slug is never just a name. It is the tenant's id (`tenancy.id_generator` is null estate-wide), the subdomain
 * label its {@see Tenant::primaryHost()} is built from, a Postgres schema name (`tenancy.database.prefix` . id), a
 * `search_path` entry, a Redis prefix and a search-index name. So it is held to the narrowest grammar all of those
 * accept: lowercase letters, digits and hyphens, starting and ending with a letter or digit, at most
 * {@see self::MAX_LENGTH} characters — the 63-byte Postgres identifier limit less the seven bytes of `tenant_`.
 *
 * What it rules out is the point. A dot made the slug a *different host* (`brk-x.evil.example` was linked as-is), an
 * `@` turned everything before it into URL userinfo (`brk-x@evil.example` sent the workspace hop — and the session
 * token in its fragment — to `evil.example`), and a slash, colon or whitespace changes what a URL authority means.
 * None of them can name a schema or a subdomain anyway.
 *
 * Every door that accepts a slug validates against THIS class: the operator/broker provisioning request, the create
 * form's declared shape ({@see Data\CreateTenantData}), and tower's `TenantProvisioning::assertPrerequisites()` for
 * the commands and seeders. Rows written before the grammar existed are reported, never rewritten, by
 * {@see Doctor\TenantHostAudit}.
 */
final class TenantSlug
{
    public const MAX_LENGTH = 56;

    /**
     * `D` pins `$` to the very end of the subject. Without it PCRE lets `$` match before a trailing newline, so
     * "acme\n" would pass a check whose whole job is to keep control characters out of a URL authority.
     */
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,54}[a-z0-9])?$/D';

    /** The one sentence every refusal of a slug says, so the operator form, the broker API and the CLI agree. */
    public const RULE = 'A workspace slug may use only lowercase letters, numbers and hyphens, must start and end with a letter or number, and may be at most 56 characters long.';

    /**
     * Fold case and surrounding whitespace — the two differences a person typing a slug does not mean. Nothing else is
     * rewritten: an `@`, a dot or an inner space is refused, never quietly "fixed" into some other tenant's slug.
     */
    public static function normalize(string $slug): string
    {
        return strtolower(trim($slug));
    }

    public static function isValid(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) === 1;
    }

    /**
     * The grammar as a Laravel validation rule, for a door's `rules()`. A closure rather than `regex:` on purpose: an
     * API documenter that meets `regex:` pastes the raw pattern into the published field description and fabricates
     * an "example" from it; the field's own description states the rule in words instead.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function rule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isValid($value)) {
                $fail(self::RULE);
            }
        };
    }

    /**
     * @throws InvalidArgumentException naming the slug and the rule
     */
    public static function assertValid(string $slug): string
    {
        if (! self::isValid($slug)) {
            throw new InvalidArgumentException("'{$slug}' is not a valid workspace slug. ".self::RULE);
        }

        return $slug;
    }
}
