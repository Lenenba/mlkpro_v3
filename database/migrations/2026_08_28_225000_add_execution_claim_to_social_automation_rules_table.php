<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CLAIM_EXPIRY_INDEX = 'social_automation_rules_claim_expiry_idx';

    private const TABLE = 'social_automation_rules';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $addClaimToken = ! Schema::hasColumn(self::TABLE, 'execution_claim_token');
        $addClaimExpiry = ! Schema::hasColumn(self::TABLE, 'execution_claimed_until');

        if ($addClaimToken || $addClaimExpiry) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($addClaimExpiry, $addClaimToken): void {
                if ($addClaimToken) {
                    $table->uuid('execution_claim_token')->nullable()->after('next_generation_at');
                }

                if ($addClaimExpiry) {
                    $table->timestamp('execution_claimed_until')->nullable()->after('execution_claim_token');
                }
            });
        }

        if (Schema::hasColumn(self::TABLE, 'execution_claimed_until')
            && ! Schema::hasIndex(self::TABLE, self::CLAIM_EXPIRY_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('execution_claimed_until', self::CLAIM_EXPIRY_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::CLAIM_EXPIRY_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::CLAIM_EXPIRY_INDEX);
            });
        }

        $existingColumns = collect([
            'execution_claim_token',
            'execution_claimed_until',
        ])->filter(fn (string $column): bool => Schema::hasColumn(self::TABLE, $column));

        if ($existingColumns->isNotEmpty()) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($existingColumns): void {
                $table->dropColumn($existingColumns->all());
            });
        }
    }
};
