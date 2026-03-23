<?php

use App\Services\StripePlanPriceProvisioner;
use Mockery\MockInterface;

it('passes the solo option through to the stripe plan price provisioner command', function () {
    $this->mock(StripePlanPriceProvisioner::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(function (array $options): bool {
                expect($options['dry_run'])->toBeTrue()
                    ->and($options['solo_only'])->toBeTrue()
                    ->and($options['plans'])->toBe([])
                    ->and($options['currencies'])->toBe([]);

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

    $this->artisan('billing:stripe-plan-prices', [
        '--dry-run' => true,
        '--solo' => true,
    ])->assertExitCode(0);
});
