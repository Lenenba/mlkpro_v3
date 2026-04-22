<?php

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('target_type')->default(PromotionTargetType::GLOBAL->value);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('discount_type')->default(PromotionDiscountType::PERCENTAGE->value);
            $table->decimal('discount_value', 12, 2);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default(PromotionStatus::ACTIVE->value);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->decimal('minimum_order_amount', 12, 2)->nullable();
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'start_date', 'end_date']);
            $table->index(['user_id', 'target_type', 'target_id']);
            $table->unique(['user_id', 'code']);
        });

        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->nullable();
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->json('snapshot')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['promotion_id', 'sale_id']);
            $table->index(['user_id', 'promotion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('promotions');
    }
};
