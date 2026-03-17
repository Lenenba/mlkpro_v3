<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_prospect_provider_connections')) {
            return;
        }

        Schema::create('campaign_prospect_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_key', 40);
            $table->string('label', 120);
            $table->longText('credentials')->nullable();
            $table->string('status', 30)->default('draft');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_validated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider_key'], 'campaign_provider_connections_user_provider_idx');
            $table->index(['user_id', 'status'], 'campaign_provider_connections_user_status_idx');
            $table->index(['user_id', 'is_active'], 'campaign_provider_connections_user_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_prospect_provider_connections');
    }
};
