<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->string('stripe_coupon_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        DB::table('subscription_promotions')->insert([
            'key' => 'global',
            'name' => 'Global subscription promotion',
            'is_enabled' => false,
            'discount_percent' => null,
            'stripe_coupon_id' => null,
            'last_synced_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_promotions');
    }
};
