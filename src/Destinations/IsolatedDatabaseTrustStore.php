<?php

namespace Splicewire\Beam\Tenancy\Destinations;

/**
 * Resolves the root-CA argument every Isolated Database Postgres connection verifies against
 * (tenant-database-upsell ticket 12/13). Serves BOTH {@see IsolatedDatabaseDestination} (a
 * single known CA — Laravel Cloud's Neon-backed Postgres, Let's Encrypt-issued, ticket 10's
 * live-handshake finding) and {@see CustomerSuppliedDatabaseDestination} (an arbitrary
 * customer server, any publicly-trusted CA) — and, critically, {@see
 * HybridPostgresTenantDatabaseManager::makeConnectionConfig()}, the LIVE runtime connection
 * config for BOTH destination types, which has no per-tenant destination context to branch
 * on. That last constraint rules out pinning a single vendored certificate (correct only for
 * the first case, silently wrong for the second): this instead points `sslrootcert` at the
 * OS's own full trusted-CA bundle FILE when one is discoverable, which validates exactly the
 * same trust set `sslrootcert=system` would (a publicly-trusted CA, Let's Encrypt's ISRG Root
 * X1 included, is a member of every one of these bundles) while sidestepping the `system`
 * keyword's libpq ≥16 requirement — `sslrootcert=<file>` has been supported since long before
 * that keyword existed (ticket 12's finding).
 *
 * Falls back to the `system` keyword when no known bundle path exists on this machine (e.g.
 * local dev on an OS not in the list below) — preserving the exact pre-ticket-12 behavior,
 * already confirmed working there (ticket 10: local Herd dev's libpq 17.5 connected fine).
 */
class IsolatedDatabaseTrustStore
{
    private const KNOWN_BUNDLE_PATHS = [
        '/etc/ssl/certs/ca-certificates.crt', // Debian/Ubuntu — confirmed splicewire-app's real production box (ticket 12)
        '/etc/pki/tls/certs/ca-bundle.crt', // RHEL/CentOS/Fedora family
        '/opt/homebrew/etc/openssl@3/cert.pem', // macOS Homebrew, Apple Silicon — local Herd dev
        '/usr/local/etc/openssl@3/cert.pem', // macOS Homebrew, Intel — local Herd dev
    ];

    /** The `sslrootcert` DSN value to use: an explicit bundle path if one exists here, else `system`. */
    public static function sslRootCert(): string
    {
        foreach (self::KNOWN_BUNDLE_PATHS as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return 'system';
    }
}
