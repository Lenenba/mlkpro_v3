<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_announcements')
            ->where('placement', '!=', 'internal')
            ->update(['placement' => 'internal']);
    }

    public function down(): void
    {
        // Irreversible normalization.
    }
};
