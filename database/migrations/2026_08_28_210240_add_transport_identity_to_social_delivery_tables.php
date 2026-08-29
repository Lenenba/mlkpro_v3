<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addTransportIdentityColumns('social_account_connections');
        $this->addTransportIdentityColumns('social_post_targets');
    }

    public function down(): void
    {
        $this->dropTransportIdentityColumns('social_post_targets');
        $this->dropTransportIdentityColumns('social_account_connections');
    }

    private function addTransportIdentityColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $missingColumns = collect([
            'delivery_provider',
            'transport_generation',
            'logical_destination_key',
        ])->reject(fn (string $column): bool => Schema::hasColumn($tableName, $column));

        if ($missingColumns->isEmpty()) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($missingColumns): void {
            if ($missingColumns->contains('delivery_provider')) {
                $table->string('delivery_provider', 32)->nullable();
            }

            if ($missingColumns->contains('transport_generation')) {
                $table->string('transport_generation', 32)->nullable();
            }

            if ($missingColumns->contains('logical_destination_key')) {
                $table->string('logical_destination_key', 71)->nullable();
            }
        });
    }

    private function dropTransportIdentityColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $existingColumns = collect([
            'delivery_provider',
            'transport_generation',
            'logical_destination_key',
        ])->filter(fn (string $column): bool => Schema::hasColumn($tableName, $column));

        if ($existingColumns->isEmpty()) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns->all());
        });
    }
};
