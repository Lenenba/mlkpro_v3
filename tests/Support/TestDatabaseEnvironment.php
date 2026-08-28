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

    public static function normalizeEnvironmentForExpectedDriver(): void
    {
        if (self::environmentValue('EXPECTED_TEST_DATABASE_DRIVER') !== 'sqlite') {
            return;
        }

        $connection = self::environmentValue('DB_CONNECTION');
        $database = self::environmentValue('DB_DATABASE');

        if ($connection !== 'sqlite' || ! self::isSafe('testing', $connection, $database)) {
            $connection = 'sqlite';
            $database = ':memory:';
        }

        self::setEnvironmentValue('DB_CONNECTION', $connection);
        self::setEnvironmentValue('DB_DATABASE', $database);
    }

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

    public static function assertResolvedDatabaseIsSafe(
        string $applicationEnvironment,
        string $driver,
        string $database,
    ): void {
        if (! self::isSafe($applicationEnvironment, $driver, $database)) {
            throw new RuntimeException(
                'Test execution refused: the resolved database configuration is not isolated for tests.',
            );
        }

        self::assertExpectedDriver($driver);
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
        foreach ([$_SERVER, $_ENV] as $variables) {
            if (array_key_exists($name, $variables) && is_scalar($variables[$name])) {
                return trim((string) $variables[$name]);
            }
        }

        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private static function setEnvironmentValue(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
