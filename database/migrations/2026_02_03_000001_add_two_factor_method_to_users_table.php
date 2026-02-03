<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method', 20)->nullable()->after('two_factor_last_sent_at');
            $table->text('two_factor_secret')->nullable()->after('two_factor_method');
            $table->boolean('two_factor_exempt')->default(false)->after('two_factor_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_method',
                'two_factor_secret',
                'two_factor_exempt',
            ]);
        });
    }
};
