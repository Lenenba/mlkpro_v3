<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'subtotal')) {
                $table->decimal('subtotal', 10, 2)->nullable()->after('approval_meta');
            }

            if (! Schema::hasColumn('invoices', 'tax_total')) {
                $table->decimal('tax_total', 10, 2)->nullable()->after('subtotal');
            }

            if (! Schema::hasColumn('invoices', 'billing_snapshot')) {
                $table->json('billing_snapshot')->nullable()->after('customer_snapshot');
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'tax_rate')) {
                $table->decimal('tax_rate', 7, 4)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('invoices', 'subtotal') ? 'subtotal' : null,
                Schema::hasColumn('invoices', 'tax_total') ? 'tax_total' : null,
                Schema::hasColumn('invoices', 'billing_snapshot') ? 'billing_snapshot' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 2)->nullable()->change();
            }
        });
    }
};
