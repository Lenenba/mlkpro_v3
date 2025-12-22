<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('item_type', 20)->default('product')->after('user_id');
            $table->index(['user_id', 'item_type'], 'products_user_item_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_user_item_type_index');
            $table->dropColumn('item_type');
        });
    }
};

