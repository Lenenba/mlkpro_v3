<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mega_menu_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mega_menu_column_id')->constrained('mega_menu_columns')->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('title', 160)->nullable();
            $table->string('css_classes')->nullable();
            $table->json('payload')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['mega_menu_column_id', 'sort_order'], 'mega_menu_blocks_column_sort_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mega_menu_blocks');
    }
};
