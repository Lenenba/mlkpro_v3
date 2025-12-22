<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_suspended')->default(false)->after('must_change_password');
            $table->timestamp('suspended_at')->nullable()->after('is_suspended');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
            $table->json('company_features')->nullable()->after('onboarding_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_suspended',
                'suspended_at',
                'suspension_reason',
                'company_features',
            ]);
        });
    }
};
