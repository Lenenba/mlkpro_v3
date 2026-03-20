<?php

namespace App\Services\MegaMenus;

use App\Models\MegaMenu;
use App\Models\MegaMenuBlock;
use App\Models\MegaMenuColumn;
use App\Models\MegaMenuItem;
use App\Support\MegaMenuOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MegaMenuManagerService
{
    public function __construct(
        private readonly MegaMenuPayloadSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?int $userId = null): MegaMenu
    {
        $data = $this->sanitizer->sanitize($input);

        return DB::transaction(function () use ($data, $userId) {
            $menu = new MegaMenu;
            $this->fillMenu($menu, $data, $userId, true);
            $this->replaceStructure($menu, $data['items']);
            $this->deactivateOtherActiveMenus($menu, $userId);

            return $this->fresh($menu);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(MegaMenu $menu, array $input, ?int $userId = null): MegaMenu
    {
        $data = $this->sanitizer->sanitize($input);

        return DB::transaction(function () use ($menu, $data, $userId) {
            $this->fillMenu($menu, $data, $userId, false);
            $this->replaceStructure($menu, $data['items']);
            $this->deactivateOtherActiveMenus($menu, $userId);

            return $this->fresh($menu);
        });
    }

    public function duplicate(MegaMenu $menu, ?int $userId = null): MegaMenu
    {
        $payload = $this->toPayload($this->fresh($menu));
        $payload['title'] = $this->duplicateTitle($payload['title']);
        $payload['slug'] = $this->nextAvailableSlug($payload['slug']);
        $payload['status'] = MegaMenuOptions::STATUS_DRAFT;
        $payload['ordering'] = ((int) MegaMenu::query()->max('ordering')) + 1;

        return $this->create($payload, $userId);
    }

    public function changeStatus(MegaMenu $menu, string $status, ?int $userId = null): MegaMenu
    {
        abort_unless(in_array($status, MegaMenuOptions::statuses(), true), 422);

        return DB::transaction(function () use ($menu, $status, $userId) {
            $menu->fill([
                'status' => $status,
                'updated_by' => $userId,
                'published_at' => $status === MegaMenuOptions::STATUS_ACTIVE ? now() : null,
            ]);
            $menu->save();

            $this->deactivateOtherActiveMenus($menu, $userId);

            return $this->fresh($menu);
        });
    }

    /**
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $index => $id) {
                MegaMenu::query()
                    ->whereKey((int) $id)
                    ->update(['ordering' => $index + 1]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(MegaMenu $menu): array
    {
        $menu->loadMissing([
            'items.children.children',
            'items.columns.blocks',
        ]);

        return [
            'id' => $menu->id,
            'title' => $menu->title,
            'slug' => $menu->slug,
            'status' => $menu->status,
            'display_location' => $menu->display_location,
            'custom_zone' => $menu->custom_zone,
            'description' => $menu->description,
            'css_classes' => $menu->css_classes,
            'ordering' => $menu->ordering,
            'settings' => $menu->settings ?? [],
            'items' => $menu->items->map(fn (MegaMenuItem $item) => $this->mapItem($item))->all(),
        ];
    }

    private function fillMenu(MegaMenu $menu, array $data, ?int $userId, bool $isNew): void
    {
        $ordering = (int) ($data['ordering'] ?? 0);
        if ($isNew && $ordering <= 0) {
            $ordering = ((int) MegaMenu::query()->max('ordering')) + 1;
        }

        $menu->fill([
            'title' => $data['title'],
            'slug' => $data['slug'] !== '' ? $data['slug'] : $this->nextAvailableSlug(Str::slug($data['title'] ?: 'mega-menu'), $menu->id),
            'status' => $data['status'],
            'display_location' => $data['display_location'],
            'custom_zone' => $data['custom_zone'],
            'description' => $data['description'],
            'css_classes' => $data['css_classes'],
            'ordering' => max(0, $ordering),
            'settings' => $data['settings'],
            'updated_by' => $userId,
            'published_at' => $data['status'] === MegaMenuOptions::STATUS_ACTIVE ? now() : null,
        ]);

        if ($isNew) {
            $menu->created_by = $userId;
        }

        $menu->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceStructure(MegaMenu $menu, array $items): void
    {
        $this->purgeStructure($menu);

        foreach ($items as $index => $itemData) {
            $itemData['sort_order'] = $index;
            $this->createItem($menu, null, $itemData);
        }
    }

    /**
     * @param  array<string, mixed>  $itemData
     */
    private function createItem(MegaMenu $menu, ?MegaMenuItem $parent, array $itemData): MegaMenuItem
    {
        $item = $menu->allItems()->create([
            'parent_id' => $parent?->id,
            'label' => $itemData['label'] ?? '',
            'description' => $itemData['description'] ?? null,
            'link_type' => $itemData['link_type'] ?? MegaMenuOptions::LINK_NONE,
            'link_value' => $itemData['link_value'] ?? null,
            'link_target' => $itemData['link_target'] ?? MegaMenuOptions::TARGET_SELF,
            'panel_type' => $itemData['panel_type'] ?? MegaMenuOptions::PANEL_LINK,
            'icon' => $itemData['icon'] ?? null,
            'badge_text' => $itemData['badge_text'] ?? null,
            'badge_variant' => $itemData['badge_variant'] ?? null,
            'is_visible' => (bool) ($itemData['is_visible'] ?? true),
            'css_classes' => $itemData['css_classes'] ?? null,
            'settings' => $itemData['settings'] ?? [],
            'sort_order' => (int) ($itemData['sort_order'] ?? 0),
        ]);

        if (($itemData['panel_type'] ?? null) === MegaMenuOptions::PANEL_CLASSIC) {
            foreach (($itemData['children'] ?? []) as $childIndex => $childData) {
                $childData['sort_order'] = $childIndex;
                $this->createItem($menu, $item, $childData);
            }
        }

        if (($itemData['panel_type'] ?? null) === MegaMenuOptions::PANEL_MEGA) {
            foreach (($itemData['columns'] ?? []) as $columnIndex => $columnData) {
                $column = $item->columns()->create([
                    'title' => $columnData['title'] ?? null,
                    'width' => $columnData['width'] ?? '1fr',
                    'css_classes' => $columnData['css_classes'] ?? null,
                    'settings' => $columnData['settings'] ?? [],
                    'sort_order' => $columnIndex,
                ]);

                foreach (($columnData['blocks'] ?? []) as $blockIndex => $blockData) {
                    $column->blocks()->create([
                        'type' => $blockData['type'] ?? 'text',
                        'title' => $blockData['title'] ?? null,
                        'css_classes' => $blockData['css_classes'] ?? null,
                        'payload' => $blockData['payload'] ?? [],
                        'settings' => $blockData['settings'] ?? [],
                        'sort_order' => $blockIndex,
                    ]);
                }
            }
        }

        return $item;
    }

    private function purgeStructure(MegaMenu $menu): void
    {
        $itemIds = MegaMenuItem::query()
            ->where('mega_menu_id', $menu->id)
            ->pluck('id');

        if ($itemIds->isEmpty()) {
            return;
        }

        MegaMenuBlock::query()
            ->whereIn(
                'mega_menu_column_id',
                MegaMenuColumn::query()
                    ->select('id')
                    ->whereIn('mega_menu_item_id', $itemIds)
            )
            ->delete();

        MegaMenuColumn::query()
            ->whereIn('mega_menu_item_id', $itemIds)
            ->delete();

        while (true) {
            $leafIds = MegaMenuItem::query()
                ->where('mega_menu_id', $menu->id)
                ->whereNotIn(
                    'id',
                    MegaMenuItem::query()
                        ->where('mega_menu_id', $menu->id)
                        ->whereNotNull('parent_id')
                        ->pluck('parent_id')
                        ->filter()
                        ->values()
                        ->all()
                )
                ->pluck('id');

            if ($leafIds->isEmpty()) {
                break;
            }

            MegaMenuItem::query()
                ->whereIn('id', $leafIds)
                ->delete();
        }
    }

    private function deactivateOtherActiveMenus(MegaMenu $menu, ?int $userId = null): void
    {
        if ($menu->status !== MegaMenuOptions::STATUS_ACTIVE) {
            return;
        }

        MegaMenu::query()
            ->whereKeyNot($menu->id)
            ->where('display_location', $menu->display_location)
            ->where(function ($query) use ($menu) {
                if ($menu->custom_zone) {
                    $query->where('custom_zone', $menu->custom_zone);
                } else {
                    $query->whereNull('custom_zone');
                }
            })
            ->where('status', MegaMenuOptions::STATUS_ACTIVE)
            ->update([
                'status' => MegaMenuOptions::STATUS_INACTIVE,
                'updated_by' => $userId,
                'published_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function duplicateTitle(string $title): string
    {
        return Str::limit(trim($title).' Copy', 160, '');
    }

    private function nextAvailableSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug);
        $candidate = $base !== '' ? $base : 'mega-menu';
        $suffix = 2;

        while ($this->slugExists($candidate, $ignoreId)) {
            $candidate = Str::limit($base !== '' ? $base : 'mega-menu', 140, '').'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return MegaMenu::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function fresh(MegaMenu|int $menu): MegaMenu
    {
        $id = $menu instanceof MegaMenu ? $menu->id : (int) $menu;

        return MegaMenu::query()
            ->with([
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
                'items.children.children',
                'items.columns.blocks',
            ])
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItem(MegaMenuItem $item): array
    {
        $item->loadMissing(['children.children', 'columns.blocks']);

        return [
            'id' => $item->id,
            'label' => $item->label,
            'description' => $item->description,
            'link_type' => $item->link_type,
            'link_value' => $item->link_value,
            'link_target' => $item->link_target,
            'panel_type' => $item->panel_type,
            'icon' => $item->icon,
            'badge_text' => $item->badge_text,
            'badge_variant' => $item->badge_variant,
            'is_visible' => $item->is_visible,
            'css_classes' => $item->css_classes,
            'settings' => $item->settings ?? [],
            'children' => $item->children->map(fn (MegaMenuItem $child) => $this->mapItem($child))->all(),
            'columns' => $item->columns->map(function (MegaMenuColumn $column) {
                return [
                    'id' => $column->id,
                    'title' => $column->title,
                    'width' => $column->width,
                    'css_classes' => $column->css_classes,
                    'settings' => $column->settings ?? [],
                    'blocks' => $column->blocks->map(function (MegaMenuBlock $block) {
                        return [
                            'id' => $block->id,
                            'type' => $block->type,
                            'title' => $block->title,
                            'css_classes' => $block->css_classes,
                            'settings' => $block->settings ?? [],
                            'payload' => $block->payload ?? [],
                        ];
                    })->all(),
                ];
            })->all(),
        ];
    }
}
