<?php

use Tests\Support\TestDatabaseEnvironment;

$captureEnvironmentState = static function (array $names): array {
    $state = [];

    foreach ($names as $name) {
        $state[$name] = [
            'process' => getenv($name),
            'environment_exists' => array_key_exists($name, $_ENV),
            'environment' => $_ENV[$name] ?? null,
            'server_exists' => array_key_exists($name, $_SERVER),
            'server' => $_SERVER[$name] ?? null,
        ];
    }

    return $state;
};

$setEnvironmentValue = static function (string $name, string $value): void {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
};

$restoreEnvironmentState = static function (array $state): void {
    foreach ($state as $name => $originalValue) {
        if (is_string($originalValue['process'])) {
            putenv("{$name}={$originalValue['process']}");
        } else {
            putenv($name);
        }

        if ($originalValue['environment_exists']) {
            $_ENV[$name] = $originalValue['environment'];
        } else {
            unset($_ENV[$name]);
        }

        if ($originalValue['server_exists']) {
            $_SERVER[$name] = $originalValue['server'];
        } else {
            unset($_SERVER[$name]);
        }
    }
};

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

it('rejects a resolved driver that differs from the expected test driver', function () use (
    $captureEnvironmentState,
    $restoreEnvironmentState,
    $setEnvironmentValue,
) {
    $originalState = $captureEnvironmentState(['EXPECTED_TEST_DATABASE_DRIVER']);
    $setEnvironmentValue('EXPECTED_TEST_DATABASE_DRIVER', 'mysql');

    try {
        expect(fn () => TestDatabaseEnvironment::assertExpectedDriver('sqlite'))
            ->toThrow(RuntimeException::class, 'resolved database driver does not match');
    } finally {
        $restoreEnvironmentState($originalState);
    }
});

it('normalizes only the default sqlite test environment', function (
    string $expectedDriver,
    string $connection,
    string $database,
    string $normalizedConnection,
    string $normalizedDatabase,
) use ($captureEnvironmentState, $restoreEnvironmentState, $setEnvironmentValue) {
    $variables = [
        'EXPECTED_TEST_DATABASE_DRIVER' => $expectedDriver,
        'DB_CONNECTION' => $connection,
        'DB_DATABASE' => $database,
    ];
    $originalState = $captureEnvironmentState(array_keys($variables));

    try {
        foreach ($variables as $name => $value) {
            $setEnvironmentValue($name, $value);
        }

        TestDatabaseEnvironment::normalizeEnvironmentForExpectedDriver();

        expect(getenv('DB_CONNECTION'))->toBe($normalizedConnection)
            ->and($_ENV['DB_CONNECTION'])->toBe($normalizedConnection)
            ->and($_SERVER['DB_CONNECTION'])->toBe($normalizedConnection)
            ->and(getenv('DB_DATABASE'))->toBe($normalizedDatabase)
            ->and($_ENV['DB_DATABASE'])->toBe($normalizedDatabase)
            ->and($_SERVER['DB_DATABASE'])->toBe($normalizedDatabase);
    } finally {
        $restoreEnvironmentState($originalState);
    }
})->with([
    'sqlite overrides an exported local database' => [
        'sqlite',
        'mysql',
        'mlkpro_v3',
        'sqlite',
        ':memory:',
    ],
    'sqlite keeps an explicitly isolated file' => [
        'sqlite',
        'sqlite',
        '/tmp/mlkpro-v3-testing/database.sqlite',
        'sqlite',
        '/tmp/mlkpro-v3-testing/database.sqlite',
    ],
    'mysql keeps the isolated server database' => [
        'mysql',
        'mysql',
        'mlkpro_v3_ci',
        'mysql',
        'mlkpro_v3_ci',
    ],
]);

it('uses Laravel environment precedence and synchronizes contradictory stores', function () use (
    $captureEnvironmentState,
    $restoreEnvironmentState,
    $setEnvironmentValue,
) {
    $names = ['EXPECTED_TEST_DATABASE_DRIVER', 'DB_CONNECTION', 'DB_DATABASE'];
    $originalState = $captureEnvironmentState($names);

    try {
        $setEnvironmentValue('EXPECTED_TEST_DATABASE_DRIVER', 'sqlite');
        $setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $setEnvironmentValue('DB_DATABASE', ':memory:');
        $_SERVER['DB_CONNECTION'] = 'mysql';
        $_SERVER['DB_DATABASE'] = 'mlkpro_v3';

        TestDatabaseEnvironment::normalizeEnvironmentForExpectedDriver();

        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'] as $name => $expected) {
            expect(getenv($name))->toBe($expected)
                ->and($_ENV[$name])->toBe($expected)
                ->and($_SERVER[$name])->toBe($expected);
        }
    } finally {
        $restoreEnvironmentState($originalState);
    }
});

it('rejects an unsafe database from the resolved Laravel configuration', function () use (
    $captureEnvironmentState,
    $restoreEnvironmentState,
    $setEnvironmentValue,
) {
    $originalState = $captureEnvironmentState(['EXPECTED_TEST_DATABASE_DRIVER']);

    try {
        $setEnvironmentValue('EXPECTED_TEST_DATABASE_DRIVER', 'sqlite');

        expect(fn () => TestDatabaseEnvironment::assertResolvedDatabaseIsSafe('testing', 'mysql', 'mlkpro_v3'))
            ->toThrow(RuntimeException::class, 'resolved database configuration is not isolated')
            ->and(fn () => TestDatabaseEnvironment::assertResolvedDatabaseIsSafe('testing', 'mysql', 'mlkpro_v3_test'))
            ->toThrow(RuntimeException::class, 'resolved database driver does not match');

        TestDatabaseEnvironment::assertResolvedDatabaseIsSafe('testing', 'sqlite', ':memory:');
    } finally {
        $restoreEnvironmentState($originalState);
    }
});
