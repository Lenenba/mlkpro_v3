<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('loyalty_programs')) {
            Schema::create('loyalty_programs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_enabled')->default(true);
                $table->decimal('points_per_currency_unit', 8, 4)->default(1);
                $table->decimal('minimum_spend', 10, 2)->default(0);
                $table->string('rounding_mode', 20)->default('floor');
                $table->string('points_label', 40)->default('points');
                $table->timestamps();

                $table->unique('user_id');
            });
        }

        if (!Schema::hasTable('loyalty_point_ledgers')) {
            Schema::create('loyalty_point_ledgers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event', 24);
                $table->integer('points');
                $table->decimal('amount', 10, 2)->default(0);
                $table->json('meta')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'customer_id', 'created_at'], 'loyalty_ledger_user_customer_created_idx');
                $table->unique(['payment_id', 'event'], 'loyalty_ledger_payment_event_unique');
            });
        }

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'loyalty_points_balance')) {
                $table->integer('loyalty_points_balance')->default(0)->after('discount_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'loyalty_points_balance')) {
                $table->dropColumn('loyalty_points_balance');
            }
        });

        Schema::dropIfExists('loyalty_point_ledgers');
        Schema::dropIfExists('loyalty_programs');
    }
};

