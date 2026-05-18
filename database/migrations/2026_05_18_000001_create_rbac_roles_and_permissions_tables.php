<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('group')->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('company_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_editable')->default(true);
            $table->boolean('is_deletable')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('company_role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_role_id')->constrained('company_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_role_permission');
        Schema::dropIfExists('company_roles');
        Schema::dropIfExists('permissions');
    }
};
