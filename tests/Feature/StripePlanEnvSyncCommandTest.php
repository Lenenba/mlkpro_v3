<?php

use App\Services\StripePlanEnvSyncService;
use Mockery\MockInterface;

it('passes filtering options through to the stripe env sync command', function () {
    $this->mock(StripePlanEnvSyncService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(function (array $options): bool {
                expect($options['dry_run'])->toBeTrue()
                    ->and($options['solo_only'])->toBeTrue()
                    ->and($options['plans'])->toBe(['starter'])
                    ->and($options['currencies'])->toBe(['USD']);

                return true;
            })
            ->andReturn([
                'items' => [],
                'resolved' => [],
                'env_updated' => false,
                'db_synced' => false,
                'dry_run' => true,
            ]);
    });

    $this->artisan('billing:stripe-sync-env', [
        '--dry-run' => true,
        '--solo' => true,
        '--plans' => 'starter',
        '--currencies' => 'usd',
    ])->assertExitCode(0);
});
