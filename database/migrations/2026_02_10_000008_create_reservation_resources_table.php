<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->string('name', 120);
            $table->string('type', 60)->default('general');
            $table->unsignedInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'is_active'], 'reservation_resources_account_active_idx');
            $table->index(['account_id', 'team_member_id', 'is_active'], 'reservation_resources_account_member_active_idx');
            $table->index(['account_id', 'type'], 'reservation_resources_account_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_resources');
    }
};

