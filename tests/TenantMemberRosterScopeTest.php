<?php

use Illuminate\Http\Request;
use Rushing\PermissionCascade\Authorization\CredentialScopeRefused;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Splicewire\Beam\Tenancy\Http\Controllers\TenantMemberController;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/**
 * credential-scope 08: the tenant roster reads (`TenantMemberController` roles, index) ask a scoped token for
 * `viewMembers` on the tenant, as the cascade derives it (`{tenant}.view-members`, `{tenant}.{id}.view-members`), before
 * reading. The seat stays the principal half. The real-bearer proof through the flagship's tenant HTTP doors is the
 * flagship's `BearerRoutesAskANamePackagesTest`, which also proves the provider offers the name to the widest set.
 */
beforeEach(function () {
    // this harness does not register the cascade's provider (the scope seam and its singletons); a host does
    $this->app->register(PermissionCascadeServiceProvider::class);
    config(['auth.providers.users.model' => User::class]);
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    $this->tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
    tenancy()->initialize($this->tenant);
});

afterEach(function () {
    tenancy()->end();
});

function cs08bTenancyActing(array $abilities): Request
{
    $actor = User::create(['name' => 'Seat', 'email' => 'seat-'.uniqid().'@example.test', 'password' => 'x']);
    test()->actingAs($actor);
    request()->setUserResolver(fn () => $actor);
    app()->instance(CredentialScopeResolver::class, new class($abilities) implements CredentialScopeResolver
    {
        public function __construct(private array $abilities) {}

        public function resolve(): ?array
        {
            return $this->abilities;
        }
    });

    return request();
}

it('refuses both roster reads to a scoped token without the tenant\'s view-members name', function (string $verb) {
    $request = cs08bTenancyActing(['entity.view', PermissionNamer::assemble(Tenant::class, 'manage-members')]);

    expect(fn () => $verb === 'roles' ? (new TenantMemberController)->roles() : (new TenantMemberController)->index($request))
        ->toThrow(fn (CredentialScopeRefused $e) => expect($e->errorCode())->toBe('credential_scope_name_not_carried'));
})->with(['roles', 'index']);

it('admits both roster reads by the class-level or this tenant\'s own name, not another tenant\'s', function () {
    $request = cs08bTenancyActing([PermissionNamer::assemble(Tenant::class, 'view-members')]);
    expect((new TenantMemberController)->roles()->getStatusCode())->toBe(200)
        ->and((new TenantMemberController)->index($request)->getStatusCode())->toBe(200);

    $request = cs08bTenancyActing([PermissionNamer::assemble($this->tenant, 'view-members')]);
    expect((new TenantMemberController)->index($request)->getStatusCode())->toBe(200);

    $other = Tenant::create(['id' => 'other', 'name' => 'Other']);
    $request = cs08bTenancyActing([PermissionNamer::assemble($other, 'view-members')]);
    expect(fn () => (new TenantMemberController)->index($request))->toThrow(CredentialScopeRefused::class);
});
