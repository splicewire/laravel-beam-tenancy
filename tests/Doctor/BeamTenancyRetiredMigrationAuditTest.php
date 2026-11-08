<?php

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Tenancy\Doctor\BeamTenancyRetiredMigrationAudit;

/*
 * migration-flatten 06: the retired domain-purpose ALTER is reported where a host still holds its published copy, and
 * the surviving create beside it is never condemned (the parent audit's directory-aware contract).
 */
function tenancyRetiredRoot(array $published): string
{
    $root = sys_get_temp_dir().'/beam-tenancy-retired-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    foreach ($published as $name) {
        file_put_contents($root.'/'.$name, '<?php // fixture');
    }

    return $root;
}

function tenancyRetiredCleanup(string $root): void
{
    foreach ((array) glob($root.'/*.php') as $file) {
        is_string($file) && @unlink($file);
    }
    @rmdir($root);
}

it('declares the retired domain-purpose ALTER with the create that supersedes it', function () {
    expect(BeamTenancyRetiredMigrationAudit::TENANCY_RETIRED)
        ->toBe(['add_domain_purpose_to_domains_table' => 'create_domains_table']);
});

it('warns about a published copy of the retired ALTER and names its successor', function () {
    $root = tenancyRetiredRoot(['2026_09_01_000000_create_domains_table.php', '2026_09_01_000001_add_domain_purpose_to_domains_table.php']);
    try {
        $findings = (new BeamTenancyRetiredMigrationAudit($root))->run();
        $warns = array_values(array_filter($findings, fn ($f) => $f->status === DoctorStatus::Warn));
        expect($warns)->toHaveCount(1)
            ->and($warns[0]->detail)->toContain('add_domain_purpose_to_domains_table')
            ->and($warns[0]->detail)->toContain('create_domains_table');
    } finally {
        tenancyRetiredCleanup($root);
    }
});

it('passes a host that holds only the surviving create', function () {
    $root = tenancyRetiredRoot(['2026_09_01_000000_create_domains_table.php']);
    try {
        $findings = (new BeamTenancyRetiredMigrationAudit($root))->run();
        expect(array_filter($findings, fn ($f) => $f->status !== DoctorStatus::Pass))->toBeEmpty();
    } finally {
        tenancyRetiredCleanup($root);
    }
});
