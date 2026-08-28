<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reservation_settings', 'account_default_marker')) {
            return;
        }

        DB::table('reservation_settings')
            ->whereNull('team_member_id')
            ->update(['account_default_marker' => null]);

        $lastAccountId = null;
        $canonicalIds = [];

        foreach (DB::table('reservation_settings')
            ->select(['id', 'account_id'])
            ->whereNull('team_member_id')
            ->orderBy('account_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->lazy(500) as $setting) {
            $accountId = (int) $setting->account_id;
            if ($accountId === $lastAccountId) {
                continue;
            }

            $lastAccountId = $accountId;
            $canonicalIds[] = (int) $setting->id;

            if (count($canonicalIds) >= 500) {
                $this->markCanonicalRows($canonicalIds);
                $canonicalIds = [];
            }
        }

        $this->markCanonicalRows($canonicalIds);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservation_settings', 'account_default_marker')) {
            return;
        }

        DB::table('reservation_settings')->update(['account_default_marker' => null]);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function markCanonicalRows(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::table('reservation_settings')
            ->whereIn('id', $ids)
            ->update(['account_default_marker' => 1]);
    }
};
