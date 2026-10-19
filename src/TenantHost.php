<?php

namespace Splicewire\Beam\Tenancy;

/**
 * Builds the host a tenant is linked at from parts that have each been checked, instead of splicing a stored string
 * into a URL authority.
 *
 * The host travels in `https://{host}/…` — the workspace hop puts the user's session bearer token in that URL's
 * fragment — so a value that is not exactly a DNS name changes WHERE the link goes: `brk-x@evil.example` makes
 * `brk-x` the userinfo and `evil.example` the host; `brk-x.evil.example` is simply someone else's subdomain. Every
 * constructor here therefore answers a host that can carry no userinfo, port, path, query or fragment, or null.
 */
final class TenantHost
{
    /**
     * What {@see Tenant::primaryHost()} answers for a tenant no safe host can be built for.
     *
     * RFC 6761 §6.4 reserves `.invalid` so that it never resolves: a link to it dies in the viewer's resolver and no
     * request (and no token) reaches anybody. It is a fail-CLOSED string, chosen over throwing because primaryHost()
     * is read inside projections of a member's whole workspace list — one malformed legacy row must not 500 them all.
     */
    public const UNROUTABLE = 'unroutable.invalid';

    /**
     * One label as it may stand inside a host: letters, digits, hyphens — and the underscore, ONLY so that rows
     * written before {@see TenantSlug} existed (`beam_demo`, under the demo seeder's earlier bare-identifier grammar)
     * stay reachable. An underscore cannot change a URL's structure; new slugs still cannot carry one.
     */
    private const LABEL = '/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/D';

    /** A multi-label DNS name: hyphen-safe labels of at most 63, a letter-led last label, 253 in all. */
    private const NAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /**
     * `{label}.{parent}`, or null when either part is not what it claims to be. Case is folded (a host is
     * case-insensitive, and a browser lowercases it anyway); nothing else is — a label with whitespace around it is
     * refused, not trimmed into some other tenant's label.
     */
    public static function subdomain(string $label, string $parent): ?string
    {
        $label = strtolower($label);
        $parent = strtolower($parent);

        if (! self::isLabel($label) || ! (self::isLabel($parent) || self::isName($parent))) {
            return null;
        }

        return $label.'.'.$parent;
    }

    /** A custom (vanity) domain as a bare host name, or null when it is not one. */
    public static function customDomain(string $name): ?string
    {
        $name = strtolower($name);

        return self::isName($name) ? $name : null;
    }

    public static function isLabel(string $label): bool
    {
        return preg_match(self::LABEL, $label) === 1;
    }

    public static function isName(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1;
    }
}
