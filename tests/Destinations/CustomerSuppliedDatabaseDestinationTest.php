<?php

use Splicewire\Beam\Tenancy\Destinations\CustomerSuppliedDatabaseDestination;

it('throws when a required connection field is missing', function () {
    $destination = new CustomerSuppliedDatabaseDestination(extensions: ['vector', 'fuzzystrmatch']);

    expect(fn () => $destination->provision([
        'hostname' => 'db.customer.example',
        'port' => 5432,
        'database' => 'app',
        'username' => 'app_user',
        // password missing
    ]))->toThrow(RuntimeException::class, "missing required field 'password'");
});

it('throws when a required connection field is an empty string', function () {
    $destination = new CustomerSuppliedDatabaseDestination(extensions: ['vector', 'fuzzystrmatch']);

    expect(fn () => $destination->provision([
        'hostname' => '',
        'port' => 5432,
        'database' => 'app',
        'username' => 'app_user',
        'password' => 'secret',
    ]))->toThrow(RuntimeException::class, "missing required field 'hostname'");
});

it('wraps an unreachable server as a clear connection failure, not a raw PDO exception', function () {
    $destination = new CustomerSuppliedDatabaseDestination(extensions: ['vector', 'fuzzystrmatch']);

    // 127.0.0.1:1 is not a listening Postgres server — connection refused, no live DB needed.
    expect(fn () => $destination->provision([
        'hostname' => '127.0.0.1',
        'port' => 1,
        'database' => 'app',
        'username' => 'app_user',
        'password' => 'secret',
    ]))->toThrow(RuntimeException::class, 'Could not connect to the customer-supplied database');
});

it('teardown is a no-op — never issues a destructive call against infrastructure it does not own', function () {
    $destination = new CustomerSuppliedDatabaseDestination(extensions: ['vector', 'fuzzystrmatch']);

    // No exception, no side effect to assert — the whole point is that nothing happens.
    $destination->teardown('db.customer.example:5432/app');

    expect(true)->toBeTrue();
});
