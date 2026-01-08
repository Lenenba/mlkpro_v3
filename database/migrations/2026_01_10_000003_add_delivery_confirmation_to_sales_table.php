<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->timestamp('delivery_confirmed_at')->nullable()->after('pickup_confirmed_at');
            $table->unsignedBigInteger('delivery_confirmed_by_user_id')->nullable()->after('delivery_confirmed_at');
            $table->string('delivery_proof')->nullable()->after('delivery_confirmed_by_user_id');

            $table->foreign('delivery_confirmed_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['delivery_confirmed_by_user_id']);
            $table->dropColumn(['delivery_confirmed_at', 'delivery_confirmed_by_user_id', 'delivery_proof']);
        });
    }
};
