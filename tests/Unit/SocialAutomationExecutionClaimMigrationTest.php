<?php

use App\Models\SocialAutomationRule;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the autopilot execution claim additive idempotent and reversible', function () {
    try {
        expect(Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]))->toBe(0);

        $owner = User::factory()->create();
        $rule = SocialAutomationRule::query()->create([
            'user_id' => $owner->id,
            'name' => 'Execution claim migration preservation',
        ]);
        $migrationPath = database_path(
            'migrations/2026_08_28_225000_add_execution_claim_to_social_automation_rules_table.php'
        );
        /** @var Migration $migration */
        $migration = require $migrationPath;

        $migration->up();
        $migration->up();

        $columns = collect(Schema::getColumns('social_automation_rules'))->keyBy('name');
        $claimIndex = collect(Schema::getIndexes('social_automation_rules'))
            ->firstWhere('name', 'social_automation_rules_claim_expiry_idx');

        expect($columns)->toHaveKeys([
            'execution_claim_token',
            'execution_claimed_until',
        ])->and((bool) $columns['execution_claim_token']['nullable'])->toBeTrue()
            ->and($columns['execution_claim_token']['default'])->toBeNull()
            ->and((bool) $columns['execution_claimed_until']['nullable'])->toBeTrue()
            ->and($columns['execution_claimed_until']['default'])->toBeNull()
            ->and($claimIndex)->not->toBeNull()
            ->and($claimIndex['columns'])->toBe(['execution_claimed_until'])
            ->and($claimIndex['unique'])->toBeFalse();

        if (DB::connection()->getDriverName() === 'mysql') {
            expect(strtolower((string) $columns['execution_claim_token']['type']))->toBe('char(36)');
        }

        expect(DB::table('social_automation_rules')->where('id', $rule->id)->value('name'))
            ->toBe('Execution claim migration preservation');

        $migration->down();
        $migration->down();

        expect(Schema::hasColumn('social_automation_rules', 'execution_claim_token'))->toBeFalse()
            ->and(Schema::hasColumn('social_automation_rules', 'execution_claimed_until'))->toBeFalse()
            ->and(Schema::hasIndex(
                'social_automation_rules',
                'social_automation_rules_claim_expiry_idx'
            ))->toBeFalse()
            ->and(DB::table('social_automation_rules')->where('id', $rule->id)->value('name'))
            ->toBe('Execution claim migration preservation');

        $migration->up();
        $migration->up();

        expect(Schema::hasColumns('social_automation_rules', [
            'execution_claim_token',
            'execution_claimed_until',
        ]))->toBeTrue()
            ->and(Schema::hasIndex(
                'social_automation_rules',
                'social_automation_rules_claim_expiry_idx'
            ))->toBeTrue()
            ->and(DB::table('social_automation_rules')->where('id', $rule->id)->value('execution_claim_token'))
            ->toBeNull()
            ->and(DB::table('social_automation_rules')->where('id', $rule->id)->value('execution_claimed_until'))
            ->toBeNull();
    } finally {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
});
