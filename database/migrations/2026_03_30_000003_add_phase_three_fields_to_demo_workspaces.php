<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->string('provisioning_status', 40)
                ->default('ready')
                ->after('sent_at');
            $table->unsignedSmallInteger('provisioning_progress')
                ->default(100)
                ->after('provisioning_status');
            $table->string('provisioning_stage', 160)
                ->nullable()
                ->after('provisioning_progress');
            $table->text('provisioning_error')
                ->nullable()
                ->after('provisioning_stage');
            $table->timestamp('queued_at')
                ->nullable()
                ->after('provisioning_error');
            $table->timestamp('provisioning_started_at')
                ->nullable()
                ->after('queued_at');
            $table->timestamp('provisioning_finished_at')
                ->nullable()
                ->after('provisioning_started_at');
            $table->timestamp('provisioning_failed_at')
                ->nullable()
                ->after('provisioning_finished_at');
            $table->string('sales_status', 40)
                ->default('discovery')
                ->after('provisioning_failed_at');
            $table->string('prefill_source', 80)
                ->nullable()
                ->after('sales_status');
            $table->json('prefill_payload')
                ->nullable()
                ->after('prefill_source');
            $table->json('extra_access_roles')
                ->nullable()
                ->after('prefill_payload');
            $table->json('extra_access_credentials')
                ->nullable()
                ->after('extra_access_roles');

            $table->index(['provisioning_status', 'expires_at'], 'demo_workspaces_provisioning_exp_idx');
            $table->index('sales_status', 'demo_workspaces_sales_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('demo_workspaces', function (Blueprint $table) {
            $table->dropIndex('demo_workspaces_provisioning_exp_idx');
            $table->dropIndex('demo_workspaces_sales_status_idx');
            $table->dropColumn([
                'provisioning_status',
                'provisioning_progress',
                'provisioning_stage',
                'provisioning_error',
                'queued_at',
                'provisioning_started_at',
                'provisioning_finished_at',
                'provisioning_failed_at',
                'sales_status',
                'prefill_source',
                'prefill_payload',
                'extra_access_roles',
                'extra_access_credentials',
            ]);
        });
    }
};
