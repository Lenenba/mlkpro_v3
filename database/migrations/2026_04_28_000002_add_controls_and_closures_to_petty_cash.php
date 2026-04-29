<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('petty_cash_accounts', 'receipt_required_above')) {
            Schema::table('petty_cash_accounts', function (Blueprint $table) {
                $table->decimal('receipt_required_above', 12, 2)->default(0)->after('low_balance_threshold');
            });
        }

        if (! Schema::hasTable('petty_cash_closures')) {
            Schema::create('petty_cash_closures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('petty_cash_account_id')->constrained('petty_cash_accounts')->cascadeOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->decimal('expected_balance', 12, 2)->default(0);
                $table->decimal('counted_balance', 12, 2)->default(0);
                $table->decimal('difference', 12, 2)->default(0);
                $table->string('status', 50)->default('in_review');
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reopened_at')->nullable();
                $table->text('comment')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['petty_cash_account_id', 'period_start', 'period_end']);
                $table->index(['petty_cash_account_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_closures');

        if (Schema::hasTable('petty_cash_accounts') && Schema::hasColumn('petty_cash_accounts', 'receipt_required_above')) {
            Schema::table('petty_cash_accounts', function (Blueprint $table) {
                $table->dropColumn('receipt_required_above');
            });
        }
    }
};
