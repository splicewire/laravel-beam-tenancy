<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorRenderer;
use Rushing\Doctor\DoctorReport;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Tenancy\Doctor\TenantHostAudit;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The read-only look at rows written before the slug grammar (security review, 2026-10-19): which tenants fail it,
 * what is wrong with each, and where their links go now. It reports; it never rewrites.
 */
beforeEach(function () {
    config(['app.url' => 'https://app.splicewire.test', 'tenancy.central_domains' => ['app.splicewire.test']]);

    // stancl's `domains`, as the stub declares it — plus the `verified_at` stamp a custom domain is honoured by.
    Schema::create('domains', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('domain')->unique();
        $table->string('tenant_id');
        $table->boolean('is_primary')->default(false);
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();
    });
});

/** A tenant as the provisioning pipeline leaves it: the row, and its slug bound as the primary domain. */
function auditedTenant(string $slug, ?string $binding = null, bool $verified = false): void
{
    Tenant::create(['id' => $slug, 'name' => 'Audited', 'slug' => $slug]);

    DB::table('domains')->insert([
        'domain' => $binding ?? $slug,
        'tenant_id' => $slug,
        'is_primary' => true,
        'verified_at' => $verified ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('is inconclusive over zero tenants — nothing here is not measured clean', function () {
    $finding = (new TenantHostAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeFalse();
});

it('passes when every slug and binding is one lowercase DNS label', function () {
    auditedTenant('acme');
    auditedTenant('legacy-broker-shop');
    auditedTenant('vanity', 'shop.example.com', verified: true);

    $finding = (new TenantHostAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeTrue()
        ->and($finding->detail)->toContain('3 tenant(s)');
});

it('lists each failing tenant with what is wrong and where its links go now', function () {
    auditedTenant('acme');
    auditedTenant('brk-x@evil.example');
    auditedTenant('brk-x.evil.example');
    auditedTenant('beam_demo');
    auditedTenant('planted', 'planted.evil.example');

    ['checked' => $checked, 'defects' => $defects] = (new TenantHostAudit)->measure();

    expect($checked)->toBe(5)
        ->and(array_keys($defects))->toBe(['beam_demo', 'brk-x.evil.example', 'brk-x@evil.example', 'planted'])
        ->and($defects['brk-x@evil.example'])->toBe([
            "slug 'brk-x@evil.example' is not one lowercase DNS label",
            "binding 'brk-x@evil.example' is a custom domain nothing has verified, so it is never linked",
            'no safe host can be built — its links point at unroutable.invalid',
        ])
        ->and($defects['brk-x.evil.example'])->toContain('no safe host can be built — its links point at unroutable.invalid')
        ->and($defects['beam_demo'])->toBe([
            "slug 'beam_demo' is not one lowercase DNS label",
            "binding 'beam_demo' is not one lowercase DNS label",
            'still linked at beam_demo.app.splicewire.test',
        ])
        ->and($defects['planted'])->toBe([
            "binding 'planted.evil.example' is a custom domain nothing has verified, so it is never linked",
            'still linked at planted.app.splicewire.test',
        ]);

    $finding = (new TenantHostAudit)->run()[0];

    expect($finding->status)->toBe(DoctorStatus::Warn)
        ->and($finding->conclusive)->toBeTrue()
        ->and($finding->detail)->toContain('4 of 5 tenant(s)')
        ->and($finding->detail)->not->toContain('acme:');
});

it('does not take a placeholder in verified_at for proof, so such a binding is reported as unverified', function (mixed $stamp) {
    auditedTenant('vanity', 'shop.example.com');
    DB::table('domains')->where('tenant_id', 'vanity')->update(['verified_at' => $stamp]);

    ['defects' => $defects] = (new TenantHostAudit)->measure();

    expect($defects)->toBe(['vanity' => [
        "binding 'shop.example.com' is a custom domain nothing has verified, so it is never linked",
        'still linked at vanity.app.splicewire.test',
    ]]);
})->with([
    'an empty string' => [''],
    'zero' => [0],
]);

it('reads only — the rows it reports are left exactly as they were', function () {
    auditedTenant('brk-x@evil.example');
    $before = [DB::table('tenants')->get()->toArray(), DB::table('domains')->get()->toArray()];

    (new TenantHostAudit)->run();

    expect([DB::table('tenants')->get()->toArray(), DB::table('domains')->get()->toArray()])->toEqual($before);
});

it('is registered with the doctor as advisory, so host data can never fail a run', function () {
    $registration = collect(app(BeamDoctorManifest::class)->registrations())
        ->firstWhere(fn ($registration) => $registration->audit === TenantHostAudit::class);

    expect($registration)->not->toBeNull()
        ->and($registration->gate)->toBeFalse();
});

/*
 * A stored slug, id or domain is DATA, and the old slug rule let any 56-character string in. The audit exists to find
 * the planted rows, so it must not be possible for one of them to rewrite what the audit says: the doctor writes a
 * finding's detail verbatim, and a slug of `x<ESC>[2J<LF>[PASS] forged` would clear the operator's screen and then
 * print a clean line of its own over the finding that was meant to report it (security review nit, 2026-10-19).
 */

/** What a stored slug can be made to say: clear the screen, then forge a clean line of the audit's own output. */
function plantedSlug(): string
{
    return "x\e[2J\n[PASS] forged";
}

/** The audit's finding as the doctor writes it: one `write` per line, exactly what a console would be handed. @return list<string> */
function writtenLines(Finding ...$findings): array
{
    $lines = [];

    (new DoctorRenderer(function (string $line) use (&$lines): void {
        $lines[] = $line;
    }))->render(new DoctorReport(array_values($findings)));

    return $lines;
}

/** What a terminal acts on instead of printing: any C0 control (a newline included) and DEL. */
function notPrintable(): string
{
    return '/[\x00-\x1F\x7F]/';
}

it('prints a planted slug as escaped text, so it can neither clear the screen nor forge a line of the audit\'s own', function () {
    auditedTenant(plantedSlug());

    $finding = (new TenantHostAudit)->run()[0];
    $lines = writtenLines($finding);

    expect($finding->status)->toBe(DoctorStatus::Warn)
        ->and($finding->detail)->toContain('x\e[2J\n[PASS] forged')
        ->and($finding->detail)->not->toMatch(notPrintable())
        // The finding, then the doctor's own counts line — a forged `[PASS]` would have been a line of its own.
        ->and($lines)->toHaveCount(2)
        ->and($lines[0])->toStartWith('[WARN] tenancy.tenant-hosts')
        ->and(implode('', $lines))->not->toMatch(notPrintable());
});

it('escapes the planted value in the row key, the slug, the binding and the host', function () {
    $planted = plantedSlug();
    auditedTenant($planted, "{$planted}.evil.example");

    ['defects' => $defects] = (new TenantHostAudit)->measure();

    expect(array_keys($defects))->toBe(['x\e[2J\n[PASS] forged'])
        ->and($defects['x\e[2J\n[PASS] forged'])->toBe([
            "slug 'x\\e[2J\\n[PASS] forged' is not one lowercase DNS label",
            "binding 'x\\e[2J\\n[PASS] forged.evil.example' is a custom domain nothing has verified, so it is never linked",
            'no safe host can be built — its links point at unroutable.invalid',
        ])
        ->and(implode(' | ', $defects['x\e[2J\n[PASS] forged']))->not->toMatch(notPrintable());
});

it('escapes a planted id, which is printed when it differs from the slug', function () {
    Tenant::create(['id' => plantedSlug(), 'name' => 'Planted id', 'slug' => 'clean-slug']);

    ['defects' => $defects] = (new TenantHostAudit)->measure();

    expect($defects)->toBe(['x\e[2J\n[PASS] forged' => [
        "id 'x\\e[2J\\n[PASS] forged' is not one lowercase DNS label",
        'still linked at clean-slug.app.splicewire.test',
    ]]);
});

it('escapes what a terminal or a reader could be misled by that is not an ASCII control', function (string $stored, string $printed) {
    expect(TenantHostAudit::printable($stored))->toBe($printed);
})->with([
    'an escape and a newline' => ["a\e[2J\nb", 'a\e[2J\nb'],
    'a carriage return and a tab' => ["a\rb\tc", 'a\rb\tc'],
    'a NUL' => ["a\0b", 'a\x00b'],
    'DEL' => ["a\x7Fb", 'a\x7Fb'],
    'the 8-bit CSI, a C1 control some terminals obey' => ["a\u{009B}31mb", 'a\u{009B}31mb'],
    'a right-to-left override that reorders what follows it' => ["gnp.exe\u{202E}", 'gnp.exe\u{202E}'],
    'a line separator' => ["a\u{2028}b", 'a\u{2028}b'],
    'a look-alike letter, which only an escape tells from the real one' => ["c\u{0430}fe", 'c\u{0430}fe'],
    'bytes that are not UTF-8' => ["a\xFFb", 'a\xFFb'],
    // The escape character is escaped too, or a stored `\n` and a stored newline would print the same.
    'a backslash' => ['a\\nb', 'a\\\\nb'],
    'ordinary printable ASCII is left alone' => ['acme-shop.example.com (x@y:80/p?q#f)', 'acme-shop.example.com (x@y:80/p?q#f)'],
]);

it('escapes a failure\'s message too, since a driver\'s message can carry the stored value', function () {
    $audit = new class extends TenantHostAudit
    {
        public function measure(): array
        {
            throw new RuntimeException("no such column: x\e[2J\n[PASS] forged");
        }
    };

    $finding = $audit->run()[0];

    expect($finding->detail)->toContain('no such column: x\e[2J\n[PASS] forged')
        ->and($finding->detail)->not->toMatch(notPrintable())
        // The finding, the counts line, and the doctor's note that this one measured nothing — no fourth, forged one.
        ->and(writtenLines($finding))->toHaveCount(3);
});

it('escapes the message of the one row that could not be checked, and still reports the rest', function () {
    auditedTenant('fine');
    auditedTenant('broken');

    $audit = new class extends TenantHostAudit
    {
        protected function problems(Tenant $tenant): array
        {
            if ($tenant->slug === 'broken') {
                throw new RuntimeException("row said x\e[2J\n[PASS] forged");
            }

            return parent::problems($tenant);
        }
    };

    ['checked' => $checked, 'defects' => $defects] = $audit->measure();

    expect($checked)->toBe(2)
        ->and($defects)->toBe(['broken' => ['could not be checked: row said x\e[2J\n[PASS] forged']]);
});
