<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_packages', function (Blueprint $table): void {
            $table->boolean('is_recurring')->default(false)->after('is_public');
            $table->string('recurrence_frequency', 24)->nullable()->after('is_recurring');
            $table->unsignedSmallInteger('renewal_notice_days')->nullable()->after('recurrence_frequency');
            $table->index(['user_id', 'is_recurring', 'status'], 'offer_packages_recurring_status_index');
        });

        Schema::table('customer_packages', function (Blueprint $table): void {
            $table->boolean('is_recurring')->default(false)->after('currency_code');
            $table->string('recurrence_frequency', 24)->nullable()->after('is_recurring');
            $table->string('recurrence_status', 24)->nullable()->after('recurrence_frequency');
            $table->date('current_period_starts_at')->nullable()->after('recurrence_status');
            $table->date('current_period_ends_at')->nullable()->after('current_period_starts_at');
            $table->date('next_renewal_at')->nullable()->after('current_period_ends_at');
            $table->unsignedInteger('renewal_count')->default(0)->after('next_renewal_at');
            $table->foreignId('renewed_from_customer_package_id')
                ->nullable()
                ->after('renewal_count')
                ->constrained('customer_packages')
                ->nullOnDelete();
            $table->index(['user_id', 'is_recurring', 'next_renewal_at'], 'customer_packages_recurring_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_packages', function (Blueprint $table): void {
            $table->dropIndex('customer_packages_recurring_due_index');
            $table->dropConstrainedForeignId('renewed_from_customer_package_id');
            $table->dropColumn([
                'is_recurring',
                'recurrence_frequency',
                'recurrence_status',
                'current_period_starts_at',
                'current_period_ends_at',
                'next_renewal_at',
                'renewal_count',
            ]);
        });

        Schema::table('offer_packages', function (Blueprint $table): void {
            $table->dropIndex('offer_packages_recurring_status_index');
            $table->dropColumn([
                'is_recurring',
                'recurrence_frequency',
                'renewal_notice_days',
            ]);
        });
    }
};
