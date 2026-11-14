<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Tenancy\Http\Controllers\TenantMemberController;
use Splicewire\Beam\Tenancy\Seats\WorkspaceSeats;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\User;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

beforeEach(function () {
    config(['app.debug' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'session.driver' => 'array', 'auth.providers.users.model' => User::class, 'tenancy.bootstrappers' => [], 'beam.localization.pseudo' => true, 'beam.localization.supported' => ['en', 'en-XA'], 'beam.localization.http_profile' => true]);
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    $this->workspace = Tenant::create(['id' => 'localization', 'slug' => 'localization', 'name' => 'Authored Workspace']);
    $this->owner = User::create(['name' => 'Original Owner', 'email' => 'owner@localization.test']);
    $this->member = User::create(['name' => 'Original Member', 'email' => 'member@localization.test']);
    $this->workspace->users()->attach($this->owner->id, ['role' => 'owner', 'accepted_at' => '2026-01-01 00:00:00']);
    $this->workspace->users()->attach($this->member->id, ['role' => 'member', 'accepted_at' => '2026-01-02 00:00:00']);
    tenancy()->initialize($this->workspace);
    $ownerId = $this->owner->id;
    Gate::define('manageMembers', fn ($user, ?TenantContract $tenant = null) => $tenant !== null && $user->id === $ownerId && $tenant->id === 'localization');
    $this->actingAs($this->owner);
    expect($this->workspace->users()->wherePivot('role', 'owner')->count())->toBe(1);
    Route::middleware('web')->group(function () {
        Route::patch('localization/members/{id}', [TenantMemberController::class, 'updateRole']);
        Route::delete('localization/members/{id}', [TenantMemberController::class, 'remove']);
    });
});

afterEach(function () {
    tenancy()->end();
});

it('keeps the original last-owner English body and status at both real controller receivers', function (string $verb, string $message) {
    $response = $verb === 'patch'
        ? $this->patchJson('localization/members/'.$this->owner->id, ['role' => 'member'], ['Accept-Language' => 'en'])
        : $this->deleteJson('localization/members/'.$this->owner->id, [], ['Accept-Language' => 'en']);
    $response->assertStatus(422)->assertHeader('Content-Type', 'application/json');
    expect($response->baseResponse->getEncodingOptions())->toBe(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $body = $response->json();
    unset($body['code']);
    expect($body)->toBe(['message' => $message])
        ->and($this->workspace->users()->wherePivot('role', 'owner')->count())->toBe(1);
})->with([
    'role transfer' => ['patch', 'Cannot remove the last owner. Transfer ownership first.'],
    'remove' => ['delete', 'Cannot remove the last owner.'],
]);

it('marks the real last-owner response and preserves its explicit code under pseudo', function (string $verb, string $code) {
    $response = $verb === 'patch'
        ? $this->patchJson('localization/members/'.$this->owner->id, ['role' => 'member'], ['Accept-Language' => 'en-XA'])
        : $this->deleteJson('localization/members/'.$this->owner->id, [], ['Accept-Language' => 'en-XA']);
    $response->assertStatus(422)->assertJsonPath('code', $code);
    expect(PseudoLocale::isMarked($response->json('message')))->toBeTrue()
        ->and($this->workspace->users()->wherePivot('role', 'owner')->count())->toBe(1);
})->with(['role transfer' => ['patch', 'tenancy.last_owner_transfer_required'], 'remove' => ['delete', 'tenancy.last_owner']]);

// File-local helper: both named cases retain exactly the same receiver/body/mutation assertions.
$assertMembershipSuccess = function (string $locale) {
    // The controller's downstream role-mirroring seam is recorded, not allowed to switch tenant DBs in this local fixture.
    $seats = Mockery::mock(WorkspaceSeats::class);
    $seats->shouldReceive('seat')->once()->withArgs(fn ($tenant, $user, $tier) => $tenant->id === $this->workspace->id && $user->id === $this->member->id && $tier === 'admin')->andReturn(true);
    app()->instance(WorkspaceSeats::class, $seats);
    $expected = ['id' => $this->member->id, 'name' => 'Original Member', 'email' => 'member@localization.test', 'role' => 'admin', 'joinedAt' => '2026-01-02 00:00:00'];
    tenancy()->initialize($this->workspace);
    expect(tenancy()->initialized)->toBeTrue()->and(tenancy()->tenant)->toBe($this->workspace);
    $updated = $this->patchJson('localization/members/'.$this->member->id, ['role' => 'admin'], ['Accept-Language' => $locale]);
    $updated->assertOk();
    expect($updated->json('data'))->toBe($expected);
    tenancy()->initialize($this->workspace);
    expect(tenancy()->initialized)->toBeTrue()->and(tenancy()->tenant)->toBe($this->workspace);
    $removed = $this->deleteJson('localization/members/'.$this->member->id, [], ['Accept-Language' => $locale]);
    $removed->assertOk();
    expect($removed->json('data'))->toBe($expected)
        ->and($this->workspace->users()->wherePivotNull('removed_at')->find($this->member->id))->toBeNull();
    if ($locale === 'en') {
        expect($updated->json())->toBe(['message' => 'Role updated.', 'data' => $expected])
            ->and($removed->json())->toBe(['message' => 'Member removed.', 'data' => $expected]);
    } else {
        expect(PseudoLocale::isMarked($updated->json('message')))->toBeTrue()
            ->and(PseudoLocale::isMarked($removed->json('message')))->toBeTrue();
    }
};

it('keeps complete membership data and both English success sentences at the real receivers', function () use ($assertMembershipSuccess) {
    $assertMembershipSuccess->call($this, 'en');
});

it('keeps complete membership data and marks both pseudo success sentences at the real receivers', function () use ($assertMembershipSuccess) {
    $assertMembershipSuccess->call($this, 'en-XA');
});

it('still denies unauthorized malformed role input before validation', function () {
    $this->actingAs($this->member)->patchJson('localization/members/'.$this->owner->id, ['role' => ['malformed']])->assertForbidden();
    expect($this->workspace->users()->wherePivot('role', 'owner')->count())->toBe(1);
});
