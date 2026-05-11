<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type', 24);
            $table->string('status', 24)->default('draft');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('pricing_mode', 24)->default('fixed');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency_code', 3)->default('CAD');
            $table->unsignedInteger('validity_days')->nullable();
            $table->unsignedInteger('included_quantity')->nullable();
            $table->string('unit_type', 32)->nullable();
            $table->boolean('is_public')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'type', 'status']);
            $table->index(['user_id', 'is_public', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_packages');
    }
};
