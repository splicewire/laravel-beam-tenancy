<?php

use Splicewire\Beam\Tenancy\Destinations\IsolatedDatabaseTrustStore;

it('returns either a readable CA bundle path or the system fallback', function () {
    $result = IsolatedDatabaseTrustStore::sslRootCert();

    expect($result)->toBeString()->not->toBe('');

    if ($result !== 'system') {
        expect(is_readable($result))->toBeTrue();
    }
});
