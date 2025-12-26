<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_announcements', function (Blueprint $table) {
            $table->string('display_style', 20)->default('standard')->after('placement');
            $table->string('background_color', 20)->nullable()->after('display_style');
        });
    }

    public function down(): void
    {
        Schema::table('platform_announcements', function (Blueprint $table) {
            $table->dropColumn(['display_style', 'background_color']);
        });
    }
};
