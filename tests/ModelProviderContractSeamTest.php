<?php

use Splicewire\Beam\Tenancy\Tenant;

/**
 * The catalog guard checks a `provider` against an UPWARD-owned contract (tower's
 * ProvidesChatCompletions), named by string through `beam.tenancy.model_provider_contract`.
 *
 * It used to hardcode `App\Contracts\ProvidesChatCompletions`. When that contract moved into tower,
 * `class_exists()` on the old FQN went false and the guard rejected EVERY catalog entry — a guard
 * that fails closed on everything is indistinguishable from a broken one, and it took 13 host-side
 * Tlc tests down with it. These pin both halves so the next relocation is caught here.
 */
interface FakeCompletionsContract {}

class FakeCompletionsProvider implements FakeCompletionsContract {}

class NotACompletionsProvider {}

function tenantForCatalog(): Tenant
{
    return new Tenant(['id' => 'catalog-tenant']);
}

it('accepts a provider implementing the configured contract', function () {
    config(['beam.tenancy.model_provider_contract' => FakeCompletionsContract::class]);

    $tenant = tenantForCatalog()->setLlmModels([
        'my-model' => ['provider' => FakeCompletionsProvider::class],
    ]);

    expect($tenant->llm_config['models']['my-model']['provider'])->toBe(FakeCompletionsProvider::class);
});

it('rejects a provider that does not implement the configured contract', function () {
    config(['beam.tenancy.model_provider_contract' => FakeCompletionsContract::class]);

    expect(fn () => tenantForCatalog()->setLlmModels([
        'my-model' => ['provider' => NotACompletionsProvider::class],
    ]))->toThrow(InvalidArgumentException::class, 'FakeCompletionsContract');
});

it('rejects a provider naming a class that does not exist', function () {
    config(['beam.tenancy.model_provider_contract' => FakeCompletionsContract::class]);

    expect(fn () => tenantForCatalog()->setLlmModels([
        'my-model' => ['provider' => 'App\\Nope\\Missing'],
    ]))->toThrow(InvalidArgumentException::class, 'must name a `provider` class that exists');
});

/**
 * The regression itself: an uninstalled contract must NOT turn the guard into a reject-everything
 * wall. The provider-exists check still stands; only the interface half is skipped, because with no
 * interface loaded there is nothing to check against.
 */
it('still accepts a real provider when the configured contract is not installed', function () {
    config(['beam.tenancy.model_provider_contract' => 'Some\\Uninstalled\\Contract']);

    $tenant = tenantForCatalog()->setLlmModels([
        'my-model' => ['provider' => NotACompletionsProvider::class],
    ]);

    expect($tenant->llm_config['models']['my-model']['provider'])->toBe(NotACompletionsProvider::class);
});

it('ships a default contract FQN so a host gets the guard without configuring it', function () {
    $default = config('beam.tenancy.model_provider_contract');

    expect($default)->toBeString()->not->toBe('')
        ->and($default)->toBe('Splicewire\\Tower\\Contracts\\ProvidesChatCompletions');
});
