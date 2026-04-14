<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('team_member_id')->constrained('customers')->nullOnDelete();
            $table->foreignId('work_id')->nullable()->after('customer_id')->constrained('works')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->after('work_id')->constrained('sales')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->after('sale_id')->constrained('invoices')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->after('invoice_id')->constrained('campaigns')->nullOnDelete();

            $table->index(['user_id', 'customer_id']);
            $table->index(['user_id', 'work_id']);
            $table->index(['user_id', 'sale_id']);
            $table->index(['user_id', 'invoice_id']);
            $table->index(['user_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'customer_id']);
            $table->dropIndex(['user_id', 'work_id']);
            $table->dropIndex(['user_id', 'sale_id']);
            $table->dropIndex(['user_id', 'invoice_id']);
            $table->dropIndex(['user_id', 'campaign_id']);

            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('work_id');
            $table->dropConstrainedForeignId('sale_id');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('campaign_id');
        });
    }
};
