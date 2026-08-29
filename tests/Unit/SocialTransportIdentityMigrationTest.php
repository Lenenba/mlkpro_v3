<?php

use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialLegacyTransportBackfillService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the additive transport schema reversible across a real up down up cycle', function () {
    $migrationPath = database_path(
        'migrations/2026_08_28_210240_add_transport_identity_to_social_delivery_tables.php'
    );
    $baseMigrationPaths = collect(File::glob(database_path('migrations/*.php')))
        ->filter(fn (string $path): bool => strcmp(
            basename($path),
            basename($migrationPath),
        ) < 0)
        ->values()
        ->all();

    Artisan::call('migrate:fresh', [
        '--force' => true,
        '--no-interaction' => true,
        '--path' => $baseMigrationPaths,
        '--realpath' => true,
    ]);

    try {
        $owner = User::factory()->create();
        $timestamp = now();
        $connectionId = DB::table('social_account_connections')->insertGetId([
            'user_id' => $owner->id,
            'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'label' => 'Migration preservation account',
            'external_account_id' => 'migration-page',
            'status' => SocialAccountConnection::STATUS_CONNECTED,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $postId = DB::table('social_posts')->insertGetId([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'content_payload' => json_encode(
                ['text' => 'Migration preservation post'],
                JSON_THROW_ON_ERROR,
            ),
            'status' => SocialPost::STATUS_DRAFT,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $targetId = DB::table('social_post_targets')->insertGetId([
            'social_post_id' => $postId,
            'social_account_connection_id' => $connectionId,
            'status' => SocialPostTarget::STATUS_PENDING,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $migration = require $migrationPath;

        $migration->up();
        assertWp2bTransportColumnsAreAdditive();

        $migration->down();
        $migration->down();

        foreach (['social_account_connections', 'social_post_targets'] as $table) {
            expect(Schema::hasColumns($table, [
                'delivery_provider',
                'transport_generation',
                'logical_destination_key',
            ]))->toBeFalse();
        }

        expect(DB::table('social_account_connections')->where('id', $connectionId)->exists())->toBeTrue()
            ->and(DB::table('social_post_targets')->where('id', $targetId)->exists())->toBeTrue();

        expect(fn () => app(SocialLegacyTransportBackfillService::class)->preview())
            ->toThrow(LogicException::class, 'schema is not installed');

        $migration->up();
        $migration->up();
        assertWp2bTransportColumnsAreAdditive();

        expect(DB::table('social_account_connections')
            ->where('id', $connectionId)
            ->value('logical_destination_key'))->toBeNull()
            ->and(DB::table('social_post_targets')
                ->where('id', $targetId)
                ->value('logical_destination_key'))->toBeNull();
    } finally {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});

function assertWp2bTransportColumnsAreAdditive(): void
{
    $expectedLengths = [
        'delivery_provider' => 32,
        'transport_generation' => 32,
        'logical_destination_key' => 71,
    ];
    $driver = DB::connection()->getDriverName();

    foreach (['social_account_connections', 'social_post_targets'] as $table) {
        $columns = collect(Schema::getColumns($table))->keyBy('name');

        foreach ($expectedLengths as $column => $length) {
            expect($columns)->toHaveKey($column)
                ->and((bool) $columns[$column]['nullable'])->toBeTrue()
                ->and($columns[$column]['default'])->toBeNull()
                ->and((string) $columns[$column]['type_name'])->toBe('varchar');

            if ($driver === 'mysql') {
                expect(strtolower((string) $columns[$column]['type']))->toBe('varchar('.$length.')');
            }
        }

        $identityColumns = array_keys($expectedLengths);
        $indexesUsingTransportIdentity = collect(Schema::getIndexes($table))
            ->filter(fn (array $index): bool => array_intersect(
                $identityColumns,
                array_map('strtolower', (array) ($index['columns'] ?? []))
            ) !== []);

        expect($indexesUsingTransportIdentity)->toBeEmpty();
    }
}
