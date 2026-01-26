<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'requests_user_created_idx');
            $table->index(['user_id', 'customer_id'], 'requests_user_customer_idx');
            $table->index(['user_id', 'assigned_team_member_id'], 'requests_user_assignee_idx');
            $table->index(['user_id', 'next_follow_up_at'], 'requests_user_followup_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'customers_user_created_idx');
            $table->index(['user_id', 'company_name'], 'customers_user_company_idx');
            $table->index(['user_id', 'last_name'], 'customers_user_last_idx');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->index('customer_id', 'properties_customer_idx');
            $table->index('city', 'properties_city_idx');
            $table->index('country', 'properties_country_idx');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'quotes_user_created_idx');
            $table->index(['user_id', 'status'], 'quotes_user_status_idx');
            $table->index(['customer_id', 'status'], 'quotes_customer_status_idx');
            $table->index('number', 'quotes_number_idx');
        });

        Schema::table('works', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'works_user_status_idx');
            $table->index(['user_id', 'start_date'], 'works_user_start_idx');
            $table->index(['status', 'start_date'], 'works_status_start_idx');
            $table->index('completed_at', 'works_completed_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'invoices_user_status_idx');
            $table->index(['user_id', 'created_at'], 'invoices_user_created_idx');
            $table->index(['customer_id', 'status'], 'invoices_customer_status_idx');
            $table->index('number', 'invoices_number_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'payments_user_status_idx');
            $table->index(['customer_id', 'status'], 'payments_customer_status_idx');
            $table->index('paid_at', 'payments_paid_at_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'sales_user_created_idx');
            $table->index(['customer_id', 'status'], 'sales_customer_status_idx');
            $table->index('paid_at', 'sales_paid_at_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'products_user_created_idx');
            $table->index(['user_id', 'category_id'], 'products_user_category_idx');
            $table->index('stock', 'products_stock_idx');
            $table->index('tracking_type', 'products_tracking_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_user_created_idx');
            $table->dropIndex('requests_user_customer_idx');
            $table->dropIndex('requests_user_assignee_idx');
            $table->dropIndex('requests_user_followup_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_user_created_idx');
            $table->dropIndex('customers_user_company_idx');
            $table->dropIndex('customers_user_last_idx');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_customer_idx');
            $table->dropIndex('properties_city_idx');
            $table->dropIndex('properties_country_idx');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex('quotes_user_created_idx');
            $table->dropIndex('quotes_user_status_idx');
            $table->dropIndex('quotes_customer_status_idx');
            $table->dropIndex('quotes_number_idx');
        });

        Schema::table('works', function (Blueprint $table) {
            $table->dropIndex('works_user_status_idx');
            $table->dropIndex('works_user_start_idx');
            $table->dropIndex('works_status_start_idx');
            $table->dropIndex('works_completed_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_user_status_idx');
            $table->dropIndex('invoices_user_created_idx');
            $table->dropIndex('invoices_customer_status_idx');
            $table->dropIndex('invoices_number_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_user_status_idx');
            $table->dropIndex('payments_customer_status_idx');
            $table->dropIndex('payments_paid_at_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_user_created_idx');
            $table->dropIndex('sales_customer_status_idx');
            $table->dropIndex('sales_paid_at_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_user_created_idx');
            $table->dropIndex('products_user_category_idx');
            $table->dropIndex('products_stock_idx');
            $table->dropIndex('products_tracking_type_idx');
        });
    }
};
