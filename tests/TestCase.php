<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseEnvironment;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        TestDatabaseEnvironment::assertCurrentEnvironmentIsSafe();

        parent::setUp();

        TestDatabaseEnvironment::assertExpectedDriver(
            $this->app['db']->connection()->getDriverName(),
        );

        $this->withoutVite();
    }
}
