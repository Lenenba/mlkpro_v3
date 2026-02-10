<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reservation_settings')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('reservation_settings', 'deposit_required')) {
                $table->boolean('deposit_required')->default(false)->after('waitlist_enabled');
            }
            if (!Schema::hasColumn('reservation_settings', 'deposit_amount')) {
                $table->decimal('deposit_amount', 10, 2)->default(0)->after('deposit_required');
            }
            if (!Schema::hasColumn('reservation_settings', 'no_show_fee_enabled')) {
                $table->boolean('no_show_fee_enabled')->default(false)->after('deposit_amount');
            }
            if (!Schema::hasColumn('reservation_settings', 'no_show_fee_amount')) {
                $table->decimal('no_show_fee_amount', 10, 2)->default(0)->after('no_show_fee_enabled');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('reservation_settings')) {
            return;
        }

        Schema::table('reservation_settings', function (Blueprint $table) {
            foreach (['no_show_fee_amount', 'no_show_fee_enabled', 'deposit_amount', 'deposit_required'] as $column) {
                if (Schema::hasColumn('reservation_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

