<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('pickup_code', 32)->nullable()->after('scheduled_for');
            $table->timestamp('pickup_confirmed_at')->nullable()->after('pickup_code');
            $table->foreignId('pickup_confirmed_by_user_id')
                ->nullable()
                ->after('pickup_confirmed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('customer_notes')->nullable()->after('pickup_confirmed_by_user_id');
            $table->boolean('substitution_allowed')->default(true)->after('customer_notes');
            $table->text('substitution_notes')->nullable()->after('substitution_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_code',
                'pickup_confirmed_at',
                'pickup_confirmed_by_user_id',
                'customer_notes',
                'substitution_allowed',
                'substitution_notes',
            ]);
        });
    }
};
