<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Tenancy\Data\CreateTenantData;
use Splicewire\Beam\Tenancy\Localization\TenancyValidationException;
use Splicewire\Beam\Tenancy\TenantSlug;

beforeEach(function () {
    config(['app.debug' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'session.driver' => 'array', 'beam.localization.pseudo' => true, 'beam.localization.supported' => ['en', 'en-XA'], 'beam.localization.http_profile' => true]);
    // This is the package's actual owning input DTO, mounted through its ordinary REST validation entry.
    // It is not a claim that the production read-only tenants Particle permits generic writes.
    Route::middleware('web')->post('localization/tenant-input', function (Request $request) {
        $input = CreateTenantData::validateAndCreate($request);

        return response()->json(['slug' => $input->slug, 'storage' => $input->storage]);
    });
});

it('keeps complete aggregation and parent exception state while assigning one slug identity after validation', function () {
    expect(class_exists(TenancyValidationException::class))->toBeTrue();
    $validator = Validator::make(['slug' => str_repeat('A', 57), 'storage' => 'isolated'], ['slug' => ['max:56', 'regex:'.TenantSlug::PATTERN], 'storage' => ['in:pooled,schema']], CreateTenantData::messages());
    $validator->after(fn ($value) => $value->errors()->add('external', 'An independent after-hook refusal.'));
    CreateTenantData::withValidator($validator);
    try {
        $validator->validate();
        $this->fail('Both slug rules, storage and the after hook must refuse.');
    } catch (ValidationException $exception) {
        expect($exception)->toBeInstanceOf(CodedValidationException::class)
            ->and($exception->validator)->toBe($validator)
            ->and(array_keys($exception->errors()))->toBe(['slug', 'storage', 'external'])
            ->and($validator->failed()['slug'])->toHaveKeys(['Max', 'Regex'])
            ->and($exception->codes())->toBe(['slug' => ['tenancy_invalid_slug']]);
        $response = response('unchanged', 409, ['X-Control' => 'same']);
        $parent = (new ValidationException($validator, $response, 'tenant'))->redirectTo('/return')->status(409);
        $adapted = (new TenancyValidationException($validator, $response, 'tenant'))->redirectTo('/return')->status(409);
        expect($adapted->getMessage())->toBe($parent->getMessage())
            ->and($adapted->errors())->toBe($parent->errors())
            ->and($adapted->response)->toBe($response)->and($adapted->errorBag)->toBe('tenant')
            ->and($adapted->redirectTo)->toBe('/return')->and($adapted->status)->toBe(409);
    }
});

it('never guesses a slug code from unrelated failures or a required-only slug', function (array $data, array $rules) {
    expect(class_exists(TenancyValidationException::class))->toBeTrue();
    $validator = Validator::make($data, $rules);
    CreateTenantData::withValidator($validator);
    try {
        $validator->validate();
        $this->fail('The control must actually fail validation.');
    } catch (ValidationException $exception) {
        expect($exception::class)->toBe(ValidationException::class)
            ->and($exception->errors())->toBe((new ValidationException($validator))->errors())
            ->and($exception->validator->failed())->toBe($validator->failed())
            ->and($exception->getMessage())->toBe((new ValidationException($validator))->getMessage());
    }
})->with([
    'no slug failure' => [['storage' => 'isolated'], ['storage' => 'in:pooled,schema']],
    'required is not a grammar failure' => [[], ['slug' => 'required']],
]);

it('preserves the owning REST DTO English body and adds a stable code', function () {
    $response = $this->postJson('localization/tenant-input', ['slug' => 'bad.slug'], ['Accept-Language' => 'en'])->assertUnprocessable();
    $body = $response->json();
    $this->assertArrayHasKey('codes', $body);
    expect($body['codes'])->toBe(['slug' => ['tenancy_invalid_slug']]);
    unset($body['codes']);
    expect($body)->toBe(['message' => TenantSlug::RULE, 'errors' => ['slug' => [TenantSlug::RULE]]]);
    $this->postJson('localization/tenant-input', ['slug' => '  Acme-Shop '])->assertOk()->assertExactJson(['slug' => 'acme-shop', 'storage' => null]);
});

it('marks the owning REST DTO grammar message and retains the same stable code under pseudo locale', function () {
    $body = $this->postJson('localization/tenant-input', ['slug' => 'bad.slug'], ['Accept-Language' => 'en-XA'])->assertUnprocessable()->json();
    $this->assertArrayHasKey('codes', $body);
    expect($body['codes'])->toBe(['slug' => ['tenancy_invalid_slug']])
        ->and(PseudoLocale::isMarked($body['errors']['slug'][0]))->toBeTrue();
});

it('keeps non-JSON redirects and the defensive raw slug diagnostic unchanged', function () {
    $this->from('/tenant-form')->post('localization/tenant-input', ['slug' => 'bad.slug'])->assertRedirect('/tenant-form')->assertSessionHasErrors('slug');
    app()->setLocale('en-XA');
    expect(fn () => TenantSlug::assertValid('bad.slug'))->toThrow(InvalidArgumentException::class, TenantSlug::RULE);
    expect(Validator::make(['slug' => 'bad.slug'], ['slug' => [TenantSlug::rule()]])->errors()->first('slug'))->not->toBe(TenantSlug::RULE);
});

it('keeps storage-only REST validation as the original plain JSON envelope without codes', function () {
    $response = $this->postJson('localization/tenant-input', ['slug' => 'valid-slug', 'storage' => 'isolated'], ['Accept-Language' => 'en'])->assertUnprocessable();
    expect($response->json())->toBe([
        'message' => 'The selected storage is invalid.',
        'errors' => ['storage' => ['The selected storage is invalid.']],
    ])->and($response->json())->not->toHaveKey('codes');
});
