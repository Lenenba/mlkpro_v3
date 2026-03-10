<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

main($argv);

function main(array $argv): void
{
    $options = parseArguments($argv);

    if ($options['help']) {
        printHelp();
        return;
    }

    try {
        $result = app(App\Services\StripePlanPriceProvisioner::class)->execute([
            'dry_run' => $options['dry_run'],
            'live' => $options['live'],
            'plans' => $options['plans'],
            'currencies' => $options['currencies'],
            'write_env' => $options['write_env'],
            'sync_db' => $options['sync_db'],
        ]);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
        exit(1);
    }

    foreach (collect($result['items'])->groupBy('plan_code') as $planCode => $items) {
        echo strtoupper((string) $planCode).PHP_EOL;
        foreach ($items as $item) {
            echo sprintf(
                "  %s %s %s %.2f/month\n",
                str_pad((string) $item['action'], 12, ' ', STR_PAD_RIGHT),
                (string) $item['currency_code'],
                (string) ($item['stripe_price_id'] ?? $item['env_key']),
                (float) $item['amount'],
            );
        }
    }

    echo PHP_EOL.'Environment values:'.PHP_EOL;
    foreach ($result['resolved'] as $envKey => $priceId) {
        echo $envKey.'='.$priceId.PHP_EOL;
    }

    if ($result['env_updated']) {
        echo PHP_EOL.'Updated '.$options['write_env'].PHP_EOL;
    }

    if ($result['db_synced']) {
        echo PHP_EOL."Synchronized plan_prices in the database.\n";
    } elseif (! $result['dry_run'] && ! $options['sync_db']) {
        echo PHP_EOL."Next step: php artisan db:seed --class=PlanCatalogSeeder\n";
    } elseif ($result['dry_run'] && $options['sync_db']) {
        echo PHP_EOL."Dry run: database was not updated.\n";
    }
}

function parseArguments(array $argv): array
{
    $options = [
        'dry_run' => false,
        'live' => false,
        'help' => false,
        'plans' => [],
        'currencies' => [],
        'write_env' => null,
        'sync_db' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if ($argument === '--live') {
            $options['live'] = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($argument === '--sync-db') {
            $options['sync_db'] = true;
            continue;
        }

        if (str_starts_with($argument, '--plans=')) {
            $options['plans'] = parseCsv(substr($argument, strlen('--plans=')));
            continue;
        }

        if (str_starts_with($argument, '--currencies=')) {
            $options['currencies'] = array_map('strtoupper', parseCsv(substr($argument, strlen('--currencies='))));
            continue;
        }

        if (str_starts_with($argument, '--write-env=')) {
            $path = trim(substr($argument, strlen('--write-env=')));
            $options['write_env'] = $path !== '' ? $path : null;
            continue;
        }

        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(1);
    }

    return $options;
}

function parseCsv(string $value): array
{
    $parts = array_map('trim', explode(',', $value));

    return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
}

function printHelp(): void
{
    echo <<<'TEXT'
Create or reuse monthly Stripe plan prices for the configured multi-currency catalog.

Usage:
  php scripts/create_stripe_multicurrency_plan_prices.php [options]

Options:
  --dry-run                 Show what would happen without creating prices.
  --live                    Required when STRIPE_SECRET is a live key.
  --plans=starter,growth    Limit the run to specific plan codes.
  --currencies=EUR,USD      Limit the run to specific currencies.
  --write-env=.env          Update a local env file with the resolved price IDs.
  --sync-db                 Upsert the resolved Stripe price IDs into plan_prices.
  --help                    Show this help.
TEXT;

    echo PHP_EOL;
}
