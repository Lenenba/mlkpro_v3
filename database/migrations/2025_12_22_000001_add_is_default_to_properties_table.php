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
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('type');
            $table->index(['customer_id', 'is_default']);
        });

        $firstProperties = DB::table('properties')
            ->select('customer_id', DB::raw('MIN(id) as id'))
            ->groupBy('customer_id')
            ->get();

        foreach ($firstProperties as $row) {
            DB::table('properties')
                ->where('id', $row->id)
                ->update(['is_default' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'is_default']);
            $table->dropColumn('is_default');
        });
    }
};

