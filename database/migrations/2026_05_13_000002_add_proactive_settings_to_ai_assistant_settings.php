<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table): void {
            $table->boolean('enable_proactive_suggestions')->default(true)->after('require_human_validation');
            $table->boolean('enable_upsell_suggestions')->default(false)->after('enable_proactive_suggestions');
            $table->boolean('enable_client_history_recommendations')->default(false)->after('enable_upsell_suggestions');
            $table->unsignedTinyInteger('max_suggestions_per_response')->default(3)->after('enable_client_history_recommendations');
            $table->boolean('require_confirmation_before_ai_action')->default(true)->after('max_suggestions_per_response');
            $table->boolean('allow_ai_to_choose_earliest_slot')->default(true)->after('require_confirmation_before_ai_action');
            $table->boolean('allow_ai_to_recommend_staff')->default(true)->after('allow_ai_to_choose_earliest_slot');
            $table->boolean('allow_ai_to_recommend_services')->default(true)->after('allow_ai_to_recommend_staff');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'enable_proactive_suggestions',
                'enable_upsell_suggestions',
                'enable_client_history_recommendations',
                'max_suggestions_per_response',
                'require_confirmation_before_ai_action',
                'allow_ai_to_choose_earliest_slot',
                'allow_ai_to_recommend_staff',
                'allow_ai_to_recommend_services',
            ]);
        });
    }
};
