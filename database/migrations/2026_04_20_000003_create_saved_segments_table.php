<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module', 40);
            $table->string('name');
            $table->string('description', 1024)->nullable();
            $table->json('filters')->nullable();
            $table->json('sort')->nullable();
            $table->string('search_term', 255)->nullable();
            $table->boolean('is_shared')->default(false);
            $table->unsignedInteger('cached_count')->default(0);
            $table->timestamp('last_resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'module', 'name'], 'saved_segments_user_module_name_unique');
            $table->index(['user_id', 'module', 'updated_at'], 'saved_segments_user_module_updated_idx');
            $table->index(['user_id', 'module', 'is_shared'], 'saved_segments_user_module_shared_idx');
        });
    }

    public function down(): void
    {
        Schema::table('saved_segments', function (Blueprint $table) {
            $table->dropIndex('saved_segments_user_module_updated_idx');
            $table->dropIndex('saved_segments_user_module_shared_idx');
            $table->dropUnique('saved_segments_user_module_name_unique');
        });

        Schema::dropIfExists('saved_segments');
    }
};
