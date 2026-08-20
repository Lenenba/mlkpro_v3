<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_LENGTH = 20;

    private const SCENARIO_LENGTH = 160;

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Accommodates the "scenario:" namespace and a 120-character scenario key.
            $table->string('demo_type', self::SCENARIO_LENGTH)->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasOversizedDemoTypes = false;

        DB::table('users')
            ->select(['id', 'demo_type'])
            ->whereNotNull('demo_type')
            ->orderBy('id')
            ->chunkById(500, function ($users) use (&$hasOversizedDemoTypes): bool {
                foreach ($users as $user) {
                    if (mb_strlen((string) $user->demo_type) > self::LEGACY_LENGTH) {
                        $hasOversizedDemoTypes = true;

                        return false;
                    }
                }

                return true;
            });

        if ($hasOversizedDemoTypes) {
            throw new RuntimeException(
                'Cannot restore users.demo_type to 20 characters while namespaced scenario identifiers exist.'
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('demo_type', self::LEGACY_LENGTH)->nullable()->change();
        });
    }
};
