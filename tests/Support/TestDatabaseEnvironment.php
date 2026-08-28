<?php

namespace Tests\Support;

use RuntimeException;

final class TestDatabaseEnvironment
{
    /**
     * @var list<string>
     */
    private const SERVER_DATABASE_CONNECTIONS = [
        'mariadb',
        'mysql',
        'pgsql',
        'sqlsrv',
    ];

    public static function assertCurrentEnvironmentIsSafe(): void
    {
        if (self::isSafe(
            self::environmentValue('APP_ENV'),
            self::environmentValue('DB_CONNECTION'),
            self::environmentValue('DB_DATABASE'),
        )) {
            return;
        }

        throw new RuntimeException(
            'Test execution refused: APP_ENV and the database must identify an isolated test environment.',
        );
    }

    public static function assertExpectedDriver(string $actualDriver): void
    {
        $expectedDriver = self::environmentValue('EXPECTED_TEST_DATABASE_DRIVER');

        if ($expectedDriver !== '' && $actualDriver === $expectedDriver) {
            return;
        }

        throw new RuntimeException('Test execution refused: the resolved database driver does not match the expected test driver.');
    }

    public static function isSafe(string $applicationEnvironment, string $connection, string $database): bool
    {
        if ($applicationEnvironment !== 'testing' || $connection === '' || $database === '') {
            return false;
        }

        if ($connection === 'sqlite') {
            return $database === ':memory:' || self::containsTestMarker($database);
        }

        return in_array($connection, self::SERVER_DATABASE_CONNECTIONS, true)
            && self::containsTestMarker($database);
    }

    private static function containsTestMarker(string $database): bool
    {
        return preg_match('/(?:^|[\\/_.-])(?:ci|test|testing)(?:$|[\\/_.-])/i', $database) === 1;
    }

    private static function environmentValue(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
