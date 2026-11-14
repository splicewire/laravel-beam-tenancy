<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Tenancy\Exceptions\SoleTenantNotIdentified;
use Splicewire\Beam\Tenancy\Http\Middleware\InitializeTenancyBySoleTenant;
use Splicewire\Beam\Tenancy\Localization\TenancyResponses;
use Splicewire\Beam\Tenancy\Resolvers\SoleTenantResolver;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\Tests\Fixtures\RecordingBootstrapper;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['app.debug' => false, 'tenancy.bootstrappers' => [RecordingBootstrapper::class], 'beam.localization.pseudo' => true, 'beam.localization.supported' => ['en', 'en-XA'], 'beam.localization.http_profile' => true]);
    app()->singleton(Tenancy::class);
    app()->bind(TenantContract::class, fn ($app) => $app[Tenancy::class]->tenant);
    InitializeTenancyBySoleTenant::$onFail = null;
    Route::middleware(InitializeTenancyBySoleTenant::class)->get('localization/sole', fn () => response()->json(['tenant' => tenant()?->getTenantKey()]));
});

afterEach(function () {
    InitializeTenancyBySoleTenant::$onFail = null;
    tenancy()->end();
});

it('preserves the original English sole failure and marks its real catch-render pseudo response', function (bool $ambiguous, string $english, string $code) {
    if ($ambiguous) {
        Tenant::create(['id' => 'one', 'slug' => 'one']);
        Tenant::create(['id' => 'two', 'slug' => 'two']);
    }
    expect(Tenant::count())->toBe($ambiguous ? 2 : 0);
    $response = $this->getJson('localization/sole', ['Accept-Language' => 'en']);
    $response->assertStatus(503)->assertHeader('Content-Type', 'application/json');
    expect($response->baseResponse->getEncodingOptions())->toBe(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $body = $response->json();
    unset($body['code']);
    expect($body)->toBe(['message' => $english]);
    $pseudo = $this->getJson('localization/sole', ['Accept-Language' => 'en-XA']);
    $pseudo->assertStatus(503)->assertJsonPath('code', $code);
    expect(PseudoLocale::isMarked($pseudo->json('message')))->toBeTrue()->and(tenancy()->initialized)->toBeFalse();
})->with([
    'empty' => [false, 'This sole-tenant install has no tenant yet.', 'tenancy.sole_tenant_empty'],
    'ambiguous' => [true, 'This install is configured for one tenant but has more than one; it will not guess which to serve.', 'tenancy.sole_tenant_ambiguous'],
]);

it('keeps the concrete HTTP503 parent and original stancl exception as previous', function () {
    $this->withoutExceptionHandling();
    try {
        $this->getJson('localization/sole');
        $this->fail('The empty real resolver must refuse.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503)->and($exception->getHeaders())->toBe([])
            ->and($exception->getPrevious())->toBeInstanceOf(SoleTenantNotIdentified::class)
            ->and($exception->getPrevious())->toBeInstanceOf(TenantCouldNotBeIdentifiedException::class)
            ->and($exception->getMessage())->toBe('This sole-tenant install has no tenant yet.');
    }
});

it('still passes the original identification exception to onFail before HTTP conversion', function () {
    $seen = null;
    InitializeTenancyBySoleTenant::$onFail = function ($exception) use (&$seen) {
        $seen = $exception;

        return response()->json(['custom' => 'unchanged'], 409, ['X-Custom' => 'same']);
    };
    $this->getJson('localization/sole')->assertStatus(409)->assertHeader('X-Custom', 'same')->assertExactJson(['custom' => 'unchanged']);
    expect($seen)->toBeInstanceOf(SoleTenantNotIdentified::class)->and($seen->getMessage())->toBe('This sole-tenant install has no tenant yet.');
});

it('does not attach guessed identity or disclose unknown exceptions', function () {
    expect(class_exists(TenancyResponses::class))->toBeTrue();
    $request = Request::create('/probe', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $known = SoleTenantNotIdentified::empty();
    $unknown = new SoleTenantNotIdentified('raw caller diagnostic');
    expect(TenancyResponses::render($unknown, $request))->toBeNull()
        ->and(TenancyResponses::render(new RuntimeException('foreign', 0, $known), $request))->toBeNull()
        ->and(TenancyResponses::render(new HttpException(500, 'foreign', $known), $request))->toBeNull()
        ->and(TenancyResponses::render($known, Request::create('/browser')))->toBeNull();
});

it('still identifies and serves the sole eligible tenant unchanged', function () {
    Tenant::create(['id' => 'one', 'slug' => 'one']);
    expect(Tenant::count())->toBe(1);
    $this->getJson('localization/sole', ['Accept-Language' => 'en-XA'])->assertOk()->assertExactJson(['tenant' => 'one']);
});

it('marks only the unknown sole forward wrapper while keeping raw inner detail and no code', function () {
    $detail = 'An unknown resolver detail: provider-42 Δ <raw> :detail.';
    $failure = new SoleTenantNotIdentified($detail);
    $resolver = Mockery::mock(SoleTenantResolver::class);
    $resolver->shouldReceive('resolve')->times(3)->andThrow($failure);
    app()->instance(SoleTenantResolver::class, $resolver);

    $this->getJson('localization/sole', ['Accept-Language' => 'en'])->assertStatus(503)->assertExactJson(['message' => $detail]);
    $pseudo = $this->getJson('localization/sole', ['Accept-Language' => 'en-XA'])->assertStatus(503)->json();
    expect($pseudo)->toBe(['message' => str_replace(':detail', $detail, PseudoLocale::transform(':detail'))])
        ->and(PseudoLocale::isMarked($pseudo['message']))->toBeTrue()
        ->and(substr_count($pseudo['message'], $detail))->toBe(1)
        ->and($failure->getMessage())->toBe($detail);
    // Only the wrapper is marked; raw diagnostic bytes, including its own :detail token, are not translated.
    $this->withoutExceptionHandling();
    try {
        $this->getJson('localization/sole', ['Accept-Language' => 'en']);
        $this->fail('The unknown resolver must still refuse.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503)->and($exception->getMessage())->toBe($detail)
            ->and($exception->getPrevious())->toBe($failure)->and($exception->getHeaders())->toBe([]);
    }
});
