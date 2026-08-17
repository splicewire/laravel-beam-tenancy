<?php

use Illuminate\Support\Facades\Http;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;
use Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter;
use Splicewire\Beam\Accounts\Oidc\SigningKey;
use Splicewire\Beam\Provision\Gcp\WorkloadIdentityCredentialResolver;
use Splicewire\Beam\Provision\Tofu\CabTokenMinter;
use Splicewire\Beam\Provision\Tofu\TenantDatabaseRootConfigRenderer;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;
use Splicewire\Beam\Tenancy\Destinations\GcpCloudSqlDestination;
use Splicewire\Beam\Tenancy\Tests\Support\RecordingRunner;

const WORKLOAD_IDENTITY_PROVIDER = '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/tower-pool/providers/tower-oidc';

function makeGcpCloudSqlDestination(Result $toReturn): array
{
    $runner = new RecordingRunner($toReturn);
    $rootConfigsRoot = sys_get_temp_dir().'/beam-gcp-cloud-sql-destination-test-'.uniqid();
    $keyPath = sys_get_temp_dir().'/gcp-cloud-sql-destination-test-key-'.uniqid().'.pem';

    $signingKey = new SigningKey($keyPath);
    $signingKey->generate(2048);

    $destination = new GcpCloudSqlDestination(
        project: 'splicewire',
        region: 'us-central1',
        serviceAccountEmail: 'beam-provision@splicewire.iam.gserviceaccount.com',
        workloadIdentityProvider: WORKLOAD_IDENTITY_PROVIDER,
        modulesDir: '/fake/tofu/modules',
        rootConfigsRoot: $rootConfigsRoot,
        extensions: ['vector', 'fuzzystrmatch'],
        authorizedNetworks: [['name' => 'plesk2', 'cidr' => '203.0.113.5/32']],
        identityTokenMinter: new IdentityTokenMinter($signingKey, 'https://tower.example.test'),
        credentialResolver: new WorkloadIdentityCredentialResolver,
        renderer: new TenantDatabaseRootConfigRenderer('/fake/tofu/modules'),
        tofu: new TofuApplyDispatcher($runner, new CabTokenMinter, 'splicewire-beam-tofu-state', '/fake/tofu/modules'),
    );

    return [$destination, $runner, $rootConfigsRoot, $keyPath];
}

function fakeGcpCredentialExchange(): void
{
    Http::fake([
        'sts.googleapis.com/*' => Http::response(['access_token' => 'federated-token']),
        'iamcredentials.googleapis.com/*' => Http::response(['accessToken' => 'impersonated-token']),
        'secretmanager.googleapis.com/*' => Http::response([
            'payload' => [
                'data' => base64_encode(json_encode([
                    'connection_name' => 'splicewire:us-central1:tenant-pilot-example-com',
                    'database' => 'tenant_pilot_example_com',
                    'username' => 'tenant_pilot_example_com',
                    'password' => 'generated-secret',
                ])),
            ],
        ]),
    ]);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/gcp-cloud-sql-destination-test-key-*.pem') as $file) {
        @unlink($file);
    }
});

it('provision() renders the tenant-database-only root config, applies it, and reads the generated credential back from Secret Manager', function () {
    fakeGcpCredentialExchange();

    [$destination, $runner, $rootConfigsRoot] = makeGcpCloudSqlDestination(Result::success(json_encode([
        'action' => 'apply',
        'changeSummary' => ['add' => 5, 'change' => 0, 'remove' => 0, 'operation' => 'apply'],
        'outputs' => [
            'connection_name' => ['value' => 'splicewire:us-central1:tenant-pilot-example-com'],
            'database_name' => ['value' => 'tenant_pilot_example_com'],
            'secret_id' => ['value' => 'tenant-pilot-example-com-db-credential'],
            'public_ip_address' => ['value' => '203.0.113.9'],
        ],
    ])));

    $result = $destination->provision(['name' => 'pilot-example-com']);

    expect($result)->toBe([
        'identifier' => 'pilot-example-com',
        'database' => 'tenant_pilot_example_com',
        'connection' => [
            'hostname' => '203.0.113.9',
            'port' => 5432,
            'username' => 'tenant_pilot_example_com',
            'password' => 'generated-secret',
        ],
    ]);

    expect($runner->input['action'])->toBe('apply');
    expect($runner->input['tenantId'])->toBe('pilot-example-com');

    $rendered = json_decode(file_get_contents("{$rootConfigsRoot}/pilot-example-com/main.tf.json"), true);
    expect($rendered)->not->toHaveKey('resource');
    expect($rendered['module'])->not->toHaveKey('cloud_run_service');
    expect($rendered['module']['tenant_database'])->toMatchArray([
        'tenant_id' => 'pilot-example-com',
        'ipv4_enabled' => true,
        'secret_accessor_member' => 'serviceAccount:beam-provision@splicewire.iam.gserviceaccount.com',
        'authorized_networks' => [['name' => 'plesk2', 'cidr' => '203.0.113.5/32']],
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'sts.googleapis.com')
        && ($request['subject_token_type'] ?? null) === 'urn:ietf:params:oauth:token-type:jwt'
        && ($request['audience'] ?? null) === WORKLOAD_IDENTITY_PROVIDER);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'iamcredentials.googleapis.com')
        && str_contains($request->url(), 'beam-provision@splicewire.iam.gserviceaccount.com'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'secretmanager.googleapis.com')
        && str_contains($request->url(), 'tenant-pilot-example-com-db-credential'));
});

it('provision() throws — with the outputs it got — when a required output is missing', function () {
    fakeGcpCredentialExchange();

    [$destination] = makeGcpCloudSqlDestination(Result::success(json_encode([
        'action' => 'apply',
        'changeSummary' => ['add' => 5, 'change' => 0, 'remove' => 0, 'operation' => 'apply'],
        'outputs' => ['connection_name' => ['value' => 'splicewire:us-central1:tenant-pilot-example-com']],
    ])));

    expect(fn () => $destination->provision(['name' => 'pilot-example-com']))
        ->toThrow(RuntimeException::class, 'missing expected outputs');
});

it('provision() throws when tofu apply fails, without ever reaching Secret Manager', function () {
    fakeGcpCredentialExchange();

    [$destination] = makeGcpCloudSqlDestination(new Result(Outcome::NonZeroExit, error: 'tofu apply failed'));

    expect(fn () => $destination->provision(['name' => 'pilot-example-com']))
        ->toThrow(RuntimeException::class, 'tofu apply failed for tenant');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'secretmanager.googleapis.com'));
});

it('teardown() re-renders the same tenant-id-keyed root config and calls destroy', function () {
    fakeGcpCredentialExchange();

    [$destination, $runner, $rootConfigsRoot] = makeGcpCloudSqlDestination(Result::success(json_encode([
        'action' => 'destroy', 'changeSummary' => ['add' => 0, 'change' => 0, 'remove' => 5, 'operation' => 'destroy'], 'outputs' => [],
    ])));

    $destination->teardown('pilot-example-com');

    expect($runner->input['action'])->toBe('destroy');
    expect($runner->input['tenantId'])->toBe('pilot-example-com');
    expect("{$rootConfigsRoot}/pilot-example-com/main.tf.json")->toBeFile();
});

it('teardown() throws when tofu destroy fails', function () {
    fakeGcpCredentialExchange();

    [$destination] = makeGcpCloudSqlDestination(new Result(Outcome::NonZeroExit, error: 'destroy failed'));

    expect(fn () => $destination->teardown('pilot-example-com'))
        ->toThrow(RuntimeException::class, 'tofu destroy failed for tenant');
});

it('resolves from the container off the beam.tenancy.gcp_cloud_sql config, wired by BeamTenancyServiceProvider', function () {
    // BeamAccountsServiceProvider (the real source of the IdentityTokenMinter binding) isn't
    // registered by this package's own TestCase — instance() it directly rather than boot the
    // whole sibling package's provider just for this one binding-resolution check. In the real
    // app beam-tenancy hard-requires beam-accounts (package topology), so this binding always
    // exists there.
    $keyPath = sys_get_temp_dir().'/gcp-cloud-sql-destination-test-key-'.uniqid().'.pem';
    $signingKey = new SigningKey($keyPath);
    $signingKey->generate(2048);
    app()->instance(SigningKey::class, $signingKey);
    app()->instance(IdentityTokenMinter::class, new IdentityTokenMinter($signingKey, 'https://tower.example.test'));

    config(['beam.tenancy.gcp_cloud_sql.workload_identity_provider' => WORKLOAD_IDENTITY_PROVIDER]);

    $destination = app(GcpCloudSqlDestination::class);

    expect($destination)->toBeInstanceOf(GcpCloudSqlDestination::class);
    // Singleton: the same instance every time, matching the other two destination bindings.
    expect(app(GcpCloudSqlDestination::class))->toBe($destination);
});
