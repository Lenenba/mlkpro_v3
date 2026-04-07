<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED_DISCOUNT_PERCENTS = [20, 25, 30, 35, 40, 45, 50];

    public function up(): void
    {
        Schema::create('subscription_promotion_coupons', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('discount_percent')->unique();
            $table->string('stripe_coupon_id')->nullable()->unique();
            $table->string('name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('subscription_promotion_coupons')->insert(
            collect(self::ALLOWED_DISCOUNT_PERCENTS)
                ->map(fn (int $discountPercent) => [
                    'discount_percent' => $discountPercent,
                    'stripe_coupon_id' => null,
                    'name' => null,
                    'metadata' => json_encode([]),
                    'synced_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_promotion_coupons');
    }
};
