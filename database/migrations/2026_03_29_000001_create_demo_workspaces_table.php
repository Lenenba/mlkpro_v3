<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('prospect_name');
            $table->string('prospect_email')->nullable();
            $table->string('prospect_company')->nullable();
            $table->string('company_name');
            $table->string('company_type', 40);
            $table->string('company_sector', 80)->nullable();
            $table->string('seed_profile', 40);
            $table->unsignedSmallInteger('team_size')->default(1);
            $table->string('locale', 10)->default('fr');
            $table->string('timezone', 120)->default('America/Toronto');
            $table->text('desired_outcome')->nullable();
            $table->text('internal_notes')->nullable();
            $table->json('selected_modules');
            $table->json('configuration')->nullable();
            $table->json('seed_summary')->nullable();
            $table->string('access_email')->nullable();
            $table->text('access_password')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_seeded_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'created_at']);
            $table->index(['company_type', 'seed_profile']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_workspaces');
    }
};
