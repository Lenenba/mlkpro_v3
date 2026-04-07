<?php

use App\Models\MegaMenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $item = MegaMenuItem::query()
            ->whereNull('parent_id')
            ->where('label', 'Solutions')
            ->whereHas('menu', fn ($query) => $query->where('slug', 'main-header-menu'))
            ->first();

        if (! $item) {
            return;
        }

        $menuId = $item->mega_menu_id;
        $item->delete();

        MegaMenuItem::query()
            ->where('mega_menu_id', $menuId)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get()
            ->each(function (MegaMenuItem $sibling, int $index): void {
                $sibling->update(['sort_order' => $index]);
            });
    }

    public function down(): void
    {
        // Forward-only menu cleanup.
    }
};
