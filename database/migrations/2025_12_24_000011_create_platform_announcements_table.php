<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('audience', 30)->default('all');
            $table->string('placement', 20)->default('internal');
            $table->unsignedInteger('new_tenant_days')->nullable();
            $table->string('media_type', 20)->default('none');
            $table->string('media_url', 2048)->nullable();
            $table->string('media_path')->nullable();
            $table->string('link_label', 120)->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'placement', 'audience']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcements');
    }
};
