<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_works', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_works', 'source_details')) {
                $table->json('source_details')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_works', function (Blueprint $table): void {
            if (Schema::hasColumn('product_works', 'source_details')) {
                $table->dropColumn('source_details');
            }
        });
    }
};
