<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('phone_number');
            $table->string('company_logo')->nullable()->after('company_name');
            $table->text('company_description')->nullable()->after('company_logo');
            $table->string('company_country')->nullable()->after('company_description');
            $table->string('company_city')->nullable()->after('company_country');
            $table->string('company_type')->nullable()->after('company_city');
            $table->timestamp('onboarding_completed_at')->nullable()->after('company_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'company_name',
                'company_logo',
                'company_description',
                'company_country',
                'company_city',
                'company_type',
                'onboarding_completed_at',
            ]);
        });
    }
};

