<?php

namespace App\Services\MegaMenus;

use App\Models\MegaMenu;
use App\Models\MegaMenuBlock;
use App\Models\MegaMenuColumn;
use App\Models\MegaMenuItem;
use App\Support\MegaMenuOptions;
use Illuminate\Support\Facades\Route;

class MegaMenuRenderer
{
    public function resolveForLocation(string $location, ?string $zone = null): array
    {
        $location = in_array($location, MegaMenuOptions::displayLocations(), true)
            ? $location
            : MegaMenuOptions::LOCATION_HEADER;

        $menu = null;
        if ($zone) {
            $menu = $this->queryActive()
                ->where('display_location', $location)
                ->where('custom_zone', $zone)
                ->first();
        }

        if (!$menu) {
            $menu = $this->queryActive()
                ->where('display_location', $location)
                ->whereNull('custom_zone')
                ->first();
        }

        return $menu
            ? $this->serialize($menu, $zone ? ($menu->custom_zone ? 'custom_zone' : 'location_fallback') : 'location')
            : $this->fallback($location, $zone);
    }

    public function resolveBySlug(string $slug, bool $includeInactive = false): array
    {
        $query = MegaMenu::query()->with($this->relations())->where('slug', $slug);

        if (!$includeInactive) {
            $query->where('status', MegaMenuOptions::STATUS_ACTIVE);
        }

        $menu = $query->first();

        return $menu ? $this->serialize($menu, 'slug') : $this->fallback(MegaMenuOptions::LOCATION_CUSTOM, $slug);
    }

    public function serialize(MegaMenu $menu, string $resolvedBy = 'direct'): array
    {
        $menu->loadMissing($this->relations());

        return [
            'exists' => true,
            'is_fallback' => false,
            'resolved_by' => $resolvedBy,
            'id' => $menu->id,
            'title' => $menu->title,
            'slug' => $menu->slug,
            'status' => $menu->status,
            'display_location' => $menu->display_location,
            'custom_zone' => $menu->custom_zone,
            'description' => $menu->description,
            'css_classes' => $menu->css_classes,
            'settings' => $menu->settings ?? [],
            'items' => $menu->items->map(fn (MegaMenuItem $item) => $this->mapItem($item))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fallback(string $location, ?string $zone = null): array
    {
        return [
            'exists' => false,
            'is_fallback' => true,
            'resolved_by' => 'fallback',
            'id' => null,
            'title' => null,
            'slug' => null,
            'status' => MegaMenuOptions::STATUS_INACTIVE,
            'display_location' => $location,
            'custom_zone' => $zone,
            'description' => null,
            'css_classes' => null,
            'settings' => [],
            'items' => [],
        ];
    }

    private function queryActive()
    {
        return MegaMenu::query()
            ->with($this->relations())
            ->where('status', MegaMenuOptions::STATUS_ACTIVE)
            ->orderBy('ordering')
            ->orderByDesc('updated_at');
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'items.children.children',
            'items.columns.blocks',
        ];
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
            'resolved_href' => $this->resolveMenuLink($item->link_type, $item->link_value),
            'panel_type' => $item->panel_type,
            'icon' => $item->icon,
            'badge_text' => $item->badge_text,
            'badge_variant' => $item->badge_variant,
            'is_visible' => (bool) $item->is_visible,
            'css_classes' => $item->css_classes,
            'settings' => $item->settings ?? [],
            'children' => $item->children->map(fn (MegaMenuItem $child) => $this->mapItem($child))->all(),
            'columns' => $item->columns->map(fn (MegaMenuColumn $column) => $this->mapColumn($column))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapColumn(MegaMenuColumn $column): array
    {
        return [
            'id' => $column->id,
            'title' => $column->title,
            'width' => $column->width ?: '1fr',
            'css_classes' => $column->css_classes,
            'settings' => $column->settings ?? [],
            'blocks' => $column->blocks->map(fn (MegaMenuBlock $block) => $this->mapBlock($block))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBlock(MegaMenuBlock $block): array
    {
        $payload = is_array($block->payload) ? $block->payload : [];

        return [
            'id' => $block->id,
            'type' => $block->type,
            'title' => $block->title,
            'css_classes' => $block->css_classes,
            'settings' => $block->settings ?? [],
            'payload' => $this->mapBlockPayload($block->type, $payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mapBlockPayload(string $type, array $payload): array
    {
        return match ($type) {
            'navigation_group' => [
                ...$payload,
                'links' => collect($payload['links'] ?? [])
                    ->map(fn ($link) => [
                        ...$link,
                        'resolved_href' => $this->resolveGenericHref($link['href'] ?? null),
                    ])
                    ->values()
                    ->all(),
            ],
            'category_list' => [
                ...$payload,
                'categories' => collect($payload['categories'] ?? [])
                    ->map(fn ($category) => [
                        ...$category,
                        'resolved_href' => $this->resolveGenericHref($category['href'] ?? null),
                    ])
                    ->values()
                    ->all(),
            ],
            'cards' => [
                ...$payload,
                'cards' => collect($payload['cards'] ?? [])
                    ->map(fn ($card) => [
                        ...$card,
                        'resolved_href' => $this->resolveGenericHref($card['href'] ?? null),
                    ])
                    ->values()
                    ->all(),
            ],
            'featured_content' => [
                ...$payload,
                'resolved_cta_href' => $this->resolveGenericHref($payload['cta_href'] ?? null),
            ],
            'image' => [
                ...$payload,
                'resolved_href' => $this->resolveGenericHref($payload['href'] ?? null),
            ],
            'promo_banner' => [
                ...$payload,
                'resolved_cta_href' => $this->resolveGenericHref($payload['cta_href'] ?? null),
            ],
            'cta' => [
                ...$payload,
                'resolved_button_href' => $this->resolveGenericHref($payload['button_href'] ?? null),
            ],
            'module_shortcut' => [
                ...$payload,
                'shortcuts' => collect($payload['shortcuts'] ?? [])
                    ->map(fn ($shortcut) => [
                        ...$shortcut,
                        'resolved_href' => $this->resolveRouteName($shortcut['route_name'] ?? null),
                    ])
                    ->values()
                    ->all(),
            ],
            default => $payload,
        };
    }

    private function resolveMenuLink(?string $type, ?string $value): ?string
    {
        return match ($type) {
            MegaMenuOptions::LINK_ROUTE => $this->resolveRouteName($value),
            MegaMenuOptions::LINK_EXTERNAL_URL,
            MegaMenuOptions::LINK_INTERNAL_PAGE,
            MegaMenuOptions::LINK_ANCHOR => $this->resolveGenericHref($value),
            default => null,
        };
    }

    private function resolveRouteName(?string $routeName): ?string
    {
        $routeName = trim((string) $routeName);
        if ($routeName === '' || !Route::has($routeName)) {
            return null;
        }

        try {
            return route($routeName, [], false);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveGenericHref(?string $href): ?string
    {
        $href = trim((string) $href);
        if ($href === '') {
            return null;
        }

        if (str_starts_with($href, '/') || str_starts_with($href, '#')) {
            return $href;
        }

        return filter_var($href, FILTER_VALIDATE_URL) ? $href : null;
    }
}
