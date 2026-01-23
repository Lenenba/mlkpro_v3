<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_subscriptions', function (Blueprint $table) {
            $table->string('assistant_price_id')->nullable()->after('price_id');
            $table->string('assistant_item_id')->nullable()->after('assistant_price_id');
            $table->timestamp('assistant_enabled_at')->nullable()->after('assistant_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('stripe_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['assistant_price_id', 'assistant_item_id', 'assistant_enabled_at']);
        });
    }
};
