<?php

use App\Services\ConfiguredPlanCatalogReconciler;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ConfiguredPlanCatalogReconciler::class)->reconcile();
    }

    public function down(): void
    {
        // This reconciliation deliberately preserves existing rows and custom
        // mappings, so there is no safe automatic data rollback.
    }
};
