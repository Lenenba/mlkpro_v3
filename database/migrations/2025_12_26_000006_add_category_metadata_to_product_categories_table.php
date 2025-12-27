<?php

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
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
        });

        $categoryUsage = DB::table('products')
            ->select('category_id', DB::raw('MIN(user_id) as owner_id'), DB::raw('COUNT(DISTINCT user_id) as user_count'))
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->get();

        foreach ($categoryUsage as $usage) {
            if ((int) $usage->user_count !== 1) {
                continue;
            }

            DB::table('product_categories')
                ->where('id', $usage->category_id)
                ->whereNull('user_id')
                ->update([
                    'user_id' => $usage->owner_id,
                    'created_by_user_id' => $usage->owner_id,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('archived_at');
        });
    }
};
