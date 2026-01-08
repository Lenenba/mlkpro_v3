<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('source')->default('pos')->after('status');
            $table->string('fulfillment_method')->nullable()->after('source');
            $table->decimal('delivery_fee', 12, 2)->default(0)->after('tax_total');
            $table->string('delivery_address', 500)->nullable()->after('delivery_fee');
            $table->text('delivery_notes')->nullable()->after('delivery_address');
            $table->text('pickup_notes')->nullable()->after('delivery_notes');
            $table->timestamp('scheduled_for')->nullable()->after('pickup_notes');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'created_by_user_id',
                'source',
                'fulfillment_method',
                'delivery_fee',
                'delivery_address',
                'delivery_notes',
                'pickup_notes',
                'scheduled_for',
            ]);
        });
    }
};
