<?php

namespace Tests;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseEnvironment;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $defaultConnection = $application['config']->get('database.default');
        $connection = $application['config']->get("database.connections.{$defaultConnection}", []);
        $resolvedConnection = (new ConfigurationUrlParser)->parseConfiguration($connection);

        TestDatabaseEnvironment::assertResolvedDatabaseIsSafe(
            (string) $application->environment(),
            (string) ($resolvedConnection['driver'] ?? ''),
            (string) ($resolvedConnection['database'] ?? ''),
        );

        return $application;
    }

    protected function setUp(): void
    {
        TestDatabaseEnvironment::normalizeEnvironmentForExpectedDriver();
        TestDatabaseEnvironment::assertCurrentEnvironmentIsSafe();

        parent::setUp();

        $this->withoutVite();
    }
}
