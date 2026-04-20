<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playbooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_segment_id')->constrained('saved_segments')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module', 40);
            $table->string('name');
            $table->string('action_key', 80);
            $table->json('action_payload')->nullable();
            $table->string('schedule_type', 20)->default('manual');
            $table->string('schedule_timezone', 80)->nullable();
            $table->unsignedTinyInteger('schedule_day_of_week')->nullable();
            $table->string('schedule_time', 5)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'module', 'name'], 'playbooks_user_module_name_unique');
            $table->index(['user_id', 'module', 'is_active'], 'playbooks_user_module_active_idx');
            $table->index(['user_id', 'next_run_at'], 'playbooks_user_next_run_idx');
        });

        Schema::create('playbook_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_id')->nullable()->constrained('playbooks')->nullOnDelete();
            $table->foreignId('saved_segment_id')->nullable()->constrained('saved_segments')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module', 40);
            $table->string('action_key', 80);
            $table->string('origin', 20)->default('manual');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('selected_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'playbook_runs_user_status_idx');
            $table->index(['playbook_id', 'created_at'], 'playbook_runs_playbook_created_idx');
            $table->index(['user_id', 'created_at'], 'playbook_runs_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playbook_runs');
        Schema::dropIfExists('playbooks');
    }
};
