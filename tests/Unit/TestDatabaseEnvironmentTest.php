<?php

use Tests\Support\TestDatabaseEnvironment;

it('accepts only explicitly isolated test databases', function (string $connection, string $database) {
    expect(TestDatabaseEnvironment::isSafe('testing', $connection, $database))->toBeTrue();
})->with([
    'sqlite memory' => ['sqlite', ':memory:'],
    'sqlite test file' => ['sqlite', '/tmp/mlkpro-v3-testing/database.sqlite'],
    'mysql test suffix' => ['mysql', 'mlkpro_v3_test'],
    'mysql ci suffix' => ['mysql', 'mlkpro_v3_ci'],
    'mariadb test prefix' => ['mariadb', 'test_mlkpro_v3'],
    'postgres testing segment' => ['pgsql', 'mlkpro_testing_v3'],
    'sql server test segment' => ['sqlsrv', 'mlkpro-test-v3'],
]);

it('rejects environments or databases that are not dedicated to tests', function (
    string $applicationEnvironment,
    string $connection,
    string $database,
) {
    expect(TestDatabaseEnvironment::isSafe($applicationEnvironment, $connection, $database))->toBeFalse();
})->with([
    'production environment' => ['production', 'mysql', 'mlkpro_v3_test'],
    'local environment' => ['local', 'sqlite', ':memory:'],
    'main mysql database' => ['testing', 'mysql', 'mlkpro_v3'],
    'ambiguous mysql database' => ['testing', 'mysql', 'mlkpro_v3_contest'],
    'ordinary sqlite file' => ['testing', 'sqlite', '/tmp/mlkpro-v3/database.sqlite'],
    'unsupported connector' => ['testing', 'mongodb', 'mlkpro_v3_test'],
    'missing database' => ['testing', 'mysql', ''],
]);

it('rejects a resolved driver that differs from the expected test driver', function () {
    $originalExpectedDriver = getenv('EXPECTED_TEST_DATABASE_DRIVER');
    putenv('EXPECTED_TEST_DATABASE_DRIVER=mysql');

    try {
        expect(fn () => TestDatabaseEnvironment::assertExpectedDriver('sqlite'))
            ->toThrow(RuntimeException::class, 'resolved database driver does not match');
    } finally {
        if (is_string($originalExpectedDriver)) {
            putenv("EXPECTED_TEST_DATABASE_DRIVER={$originalExpectedDriver}");
        } else {
            putenv('EXPECTED_TEST_DATABASE_DRIVER');
        }
    }
});
