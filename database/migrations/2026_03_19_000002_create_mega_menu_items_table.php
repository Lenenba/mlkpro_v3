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
        Schema::create('mega_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mega_menu_id')->constrained('mega_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('mega_menu_items')->cascadeOnDelete();
            $table->string('label', 160);
            $table->string('description')->nullable();
            $table->string('link_type', 40)->default('none');
            $table->string('link_value')->nullable();
            $table->string('link_target', 20)->default('_self');
            $table->string('panel_type', 20)->default('link');
            $table->string('icon', 120)->nullable();
            $table->string('badge_text', 60)->nullable();
            $table->string('badge_variant', 30)->nullable();
            $table->boolean('is_visible')->default(true);
            $table->string('css_classes')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['mega_menu_id', 'parent_id', 'sort_order'], 'mega_menu_items_parent_sort_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mega_menu_items');
    }
};
