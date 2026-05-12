<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_package_usages')) {
            return;
        }

        Schema::table('customer_package_usages', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_package_usages', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('used_at');
                $table->index(['reservation_id', 'reversed_at']);
            }

            if (! Schema::hasColumn('customer_package_usages', 'reversed_by_user_id')) {
                $table->foreignId('reversed_by_user_id')
                    ->nullable()
                    ->after('reversed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('customer_package_usages', 'reversal_reason')) {
                $table->string('reversal_reason')->nullable()->after('reversed_by_user_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_package_usages')) {
            return;
        }

        Schema::table('customer_package_usages', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_package_usages', 'reversed_by_user_id')) {
                $table->dropConstrainedForeignId('reversed_by_user_id');
            }

            foreach (['reversal_reason', 'reversed_at'] as $column) {
                if (Schema::hasColumn('customer_package_usages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
