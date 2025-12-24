<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_announcement_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')
                ->constrained('platform_announcements')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['announcement_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcement_tenants');
    }
};
