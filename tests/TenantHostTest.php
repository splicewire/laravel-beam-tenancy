<?php

use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Tenancy\Sitemap\TenantSitemapBaseUrlResolver;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantHost;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Tenancy;

/**
 * The Tenant Host is BUILT from checked parts, never copied out of a stored string (security review, 2026-10-19).
 *
 * The workspace hop sends the user's session token to `https://{primaryHost}/ui…#token=…`. `primaryHost()` used to
 * return any binding containing a dot as-is, and `CreateTenantDomain` binds the slug itself — so a broker-chosen slug
 * WAS the host. Measured by the reviewer: `brk-x@evil.example` landed the hop on `evil.example` (everything before the
 * `@` is userinfo), and `brk-x.evil.example` on an attacker-controlled subdomain.
 */
function hostTenant(string $slug, array $bindings = []): Tenant
{
    $tenant = new Tenant(['id' => $slug, 'name' => 'Host probe', 'slug' => $slug]);
    $tenant->setRelation('domains', collect(array_map(
        fn (array $binding): Domain => new Domain($binding + ['tenant_id' => $slug]),
        $bindings,
    )));

    return $tenant;
}

/** Where a browser would send a request for the hop URL built around this host. */
function hopAuthority(string $host): array
{
    $url = 'https://'.$host.'/ui/knowledge#token=secret';

    return [
        'host' => parse_url($url, PHP_URL_HOST),
        'user' => parse_url($url, PHP_URL_USER),
        'port' => parse_url($url, PHP_URL_PORT),
    ];
}

beforeEach(function () {
    config([
        'app.url' => 'https://app.splicewire.test',
        'tenancy.central_domains' => ['app.splicewire.test', 'app.splicewire.com'],
    ]);
});

it('never links the reviewer\'s userinfo probe to the host after the @', function () {
    // As provisioned before the slug grammar: CreateTenantDomain bound the slug itself, primary.
    $tenant = hostTenant('brk-x@evil.example', [['domain' => 'brk-x@evil.example', 'is_primary' => true]]);

    $host = $tenant->primaryHost();

    // Before: the hop URL's authority was `brk-x@evil.example` — host `evil.example`, userinfo `brk-x`.
    expect(hopAuthority($host)['host'])->not->toBe('evil.example')
        ->and($host)->not->toContain('evil.example')
        ->and(hopAuthority($host))->toBe(['host' => 'unroutable.invalid', 'user' => null, 'port' => null])
        ->and($host)->toBe(TenantHost::UNROUTABLE)
        ->and($tenant->routableHost())->toBeNull();
});

it('never links the reviewer\'s dotted-slug probe to the subdomain it names', function () {
    $tenant = hostTenant('brk-x.evil.example', [['domain' => 'brk-x.evil.example', 'is_primary' => true]]);

    // Before: linked as-is, an attacker-controlled subdomain of evil.example.
    expect($tenant->primaryHost())->not->toContain('evil.example')
        ->and($tenant->primaryHost())->toBe(TenantHost::UNROUTABLE)
        ->and($tenant->routableHost())->toBeNull();
});

it('refuses every other authority-changing slug rather than splicing it into a host', function (string $slug) {
    $tenant = hostTenant($slug, [['domain' => $slug, 'is_primary' => true]]);

    expect($tenant->primaryHost())->not->toContain($slug)
        ->and($tenant->primaryHost())->toBe(TenantHost::UNROUTABLE);
})->with([
    'a path' => ['acme/evil'],
    'a port' => ['acme:8443'],
    'whitespace' => ['ac me'],
    'a backslash' => ['acme\\evil.example'],
    'a query' => ['acme?x=evil.example'],
    'a fragment' => ['acme#evil'],
    'a trailing newline' => ["acme\n"],
]);

it('builds a label binding into {label}.{central domain}', function () {
    $tenant = hostTenant('acme', [['domain' => 'acme', 'is_primary' => true]]);

    expect($tenant->primaryHost())->toBe('acme.app.splicewire.test')
        ->and(hopAuthority($tenant->primaryHost())['host'])->toBe('acme.app.splicewire.test');
});

it('picks the central domain the app URL lives under', function () {
    config(['app.url' => 'https://app.splicewire.com']);

    expect(hostTenant('acme')->primaryHost())->toBe('acme.app.splicewire.com');
});

it('falls back to the slug when the tenant has no binding', function () {
    expect(hostTenant('acme')->primaryHost())->toBe('acme.app.splicewire.test');
});

it('ignores an unverified custom domain and links the slug instead', function () {
    $tenant = hostTenant('acme', [['domain' => 'shop.example.com', 'is_primary' => true]]);

    expect($tenant->primaryHost())->toBe('acme.app.splicewire.test');
});

it('honours a custom domain only once its binding carries a verification stamp', function () {
    $tenant = hostTenant('acme', [['domain' => 'shop.example.com', 'is_primary' => true, 'verified_at' => now()]]);

    expect($tenant->primaryHost())->toBe('shop.example.com');
});

it('does not honour a verified binding that is not a host name', function () {
    $tenant = hostTenant('acme', [['domain' => 'x@evil.example', 'is_primary' => true, 'verified_at' => now()]]);

    expect($tenant->primaryHost())->toBe('acme.app.splicewire.test');
});

it('keeps a pre-grammar underscore slug reachable, since an underscore cannot change a URL\'s structure', function () {
    // `beam_demo` is the demo seeder's former default and exists at the flagship.
    $tenant = hostTenant('beam_demo', [['domain' => 'beam_demo', 'is_primary' => true]]);

    expect($tenant->primaryHost())->toBe('beam_demo.app.splicewire.test');
});

it('gives the sitemap the config default rather than a URL around an unbuildable host', function () {
    $fallback = new class implements SitemapBaseUrlResolver
    {
        public function baseUrl(): string
        {
            return 'https://app.splicewire.test';
        }
    };

    // The harness boots no stancl provider, so the manager the resolver asks for is bound here.
    app()->instance(Tenancy::class, $tenancy = new Tenancy);
    $tenancy->initialized = true;
    $tenancy->tenant = hostTenant('brk-x@evil.example', [['domain' => 'brk-x@evil.example', 'is_primary' => true]]);

    $resolver = new TenantSitemapBaseUrlResolver(app(), $fallback);

    expect($resolver->baseUrl())->toBe('https://app.splicewire.test');

    $tenancy->tenant = hostTenant('acme');

    expect($resolver->baseUrl())->toBe('https://acme.app.splicewire.test');
});
