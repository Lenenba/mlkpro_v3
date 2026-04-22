<?php

use App\Enums\CustomerClientType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('client_type')->default(CustomerClientType::default()->value)->after('company_name');
            $table->string('registration_number')->nullable()->after('client_type');
            $table->string('industry')->nullable()->after('registration_number');
        });

        DB::table('customers')
            ->whereNotNull('company_name')
            ->where('company_name', '!=', '')
            ->update([
                'client_type' => CustomerClientType::COMPANY->value,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'client_type',
                'registration_number',
                'industry',
            ]);
        });
    }
};
