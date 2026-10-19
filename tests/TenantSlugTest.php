<?php

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Tenancy\Data\CreateTenantData;
use Splicewire\Beam\Tenancy\TenantSlug;

/**
 * The tenant slug grammar: ONE lowercase DNS label (security review, 2026-10-19). The reviewer's probes —
 * `brk-x@evil.example` and `brk-x.evil.example` — are the first two refusals below.
 */
it('accepts one lowercase DNS label of up to 56 characters', function (string $slug) {
    expect(TenantSlug::isValid($slug))->toBeTrue()
        ->and(TenantSlug::assertValid($slug))->toBe($slug);
})->with([
    'a word' => ['acme'],
    'one character' => ['a'],
    'digits' => ['42'],
    'hyphenated' => ['brk-x'],
    'a brokered slug' => ['legacy-broker-shop'],
    'the maximum' => [str_repeat('a', 56)],
]);

it('refuses anything that is not one lowercase DNS label', function (string $slug) {
    expect(TenantSlug::isValid($slug))->toBeFalse()
        ->and(fn () => TenantSlug::assertValid($slug))->toThrow(InvalidArgumentException::class, TenantSlug::RULE);
})->with([
    'userinfo (reviewer probe)' => ['brk-x@evil.example'],
    'a dot (reviewer probe)' => ['brk-x.evil.example'],
    'uppercase' => ['Acme'],
    'a leading hyphen' => ['-acme'],
    'a trailing hyphen' => ['acme-'],
    'an underscore' => ['beam_demo'],
    'inner whitespace' => ['ac me'],
    'a slash' => ['acme/evil'],
    'a colon' => ['acme:8443'],
    'a trailing newline' => ["acme\n"],
    'empty' => [''],
    'one too long' => [str_repeat('a', 57)],
]);

it('folds only case and surrounding whitespace — the differences a person typing a slug does not mean', function () {
    expect(TenantSlug::normalize("  Brk-X \n"))->toBe('brk-x')
        ->and(TenantSlug::normalize('BRK-X@EVIL.EXAMPLE'))->toBe('brk-x@evil.example');
});

it('ships the grammar as a validation rule a door can put in its rules()', function () {
    $passes = fn (mixed $slug): bool => Validator::make(['slug' => $slug], ['slug' => [TenantSlug::rule()]])->passes();

    expect($passes('brk-x'))->toBeTrue()
        ->and($passes('brk-x@evil.example'))->toBeFalse()
        ->and($passes('brk-x.evil.example'))->toBeFalse()
        ->and($passes(['brk-x']))->toBeFalse()
        ->and(Validator::make(['slug' => 'Brk'], ['slug' => [TenantSlug::rule()]])->errors()->first('slug'))->toBe(TenantSlug::RULE);
});

it('holds the create form\'s declared shape to the grammar, folding case first', function () {
    expect(CreateTenantData::validateAndCreate(['slug' => '  Acme-Shop '])->slug)->toBe('acme-shop');

    foreach (['brk-x@evil.example', 'brk-x.evil.example', str_repeat('a', 57)] as $slug) {
        try {
            CreateTenantData::validateAndCreate(['slug' => $slug]);
            $this->fail("'{$slug}' was accepted");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('slug')
                ->and($e->errors()['slug'])->toContain(TenantSlug::RULE);
        }
    }
});
