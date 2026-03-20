<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Requests\SuperAdmin\ReorderMegaMenusRequest;
use App\Http\Requests\SuperAdmin\StoreMegaMenuRequest;
use App\Http\Requests\SuperAdmin\UpdateMegaMenuRequest;
use App\Models\MegaMenu;
use App\Models\PlatformPage;
use App\Services\MegaMenus\MegaMenuManagerService;
use App\Services\MegaMenus\MegaMenuPayloadSanitizer;
use App\Services\MegaMenus\MegaMenuRenderer;
use App\Support\MegaMenuBlockRegistry;
use App\Support\MegaMenuOptions;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MegaMenuController extends BaseSuperAdminController
{
    public function __construct(
        private readonly MegaMenuManagerService $manager,
        private readonly MegaMenuRenderer $renderer,
        private readonly MegaMenuPayloadSanitizer $sanitizer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::MEGA_MENUS_MANAGE);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string'],
            'location' => ['nullable', 'string'],
        ]);

        $query = MegaMenu::query()
            ->with(['updatedBy:id,name,email', 'items.columns.blocks'])
            ->orderBy('ordering')
            ->orderBy('title');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status']) && in_array($filters['status'], MegaMenuOptions::statuses(), true)) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['location']) && in_array($filters['location'], MegaMenuOptions::displayLocations(), true)) {
            $query->where('display_location', $filters['location']);
        }

        $menus = $query->get()->map(function (MegaMenu $menu) {
            $topLevelItems = $menu->items->count();
            $blockCount = $menu->items->sum(fn ($item) => $item->columns->sum(fn ($column) => $column->blocks->count()));

            return [
                'id' => $menu->id,
                'title' => $menu->title,
                'slug' => $menu->slug,
                'status' => $menu->status,
                'display_location' => $menu->display_location,
                'custom_zone' => $menu->custom_zone,
                'description' => $menu->description,
                'ordering' => $menu->ordering,
                'top_level_items' => $topLevelItems,
                'block_count' => $blockCount,
                'updated_at' => $menu->updated_at?->toIso8601String(),
                'updated_by' => $menu->updatedBy ? [
                    'id' => $menu->updatedBy->id,
                    'name' => $menu->updatedBy->name,
                    'email' => $menu->updatedBy->email,
                ] : null,
            ];
        })->values();

        return Inertia::render('SuperAdmin/MegaMenus/Index', [
            'menus' => $menus,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'location' => $filters['location'] ?? '',
            ],
            'choices' => $this->choicePayload(),
            'dashboard_url' => route('superadmin.dashboard'),
            'create_url' => route('superadmin.mega-menus.create'),
            'reorder_url' => route('superadmin.mega-menus.reorder'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::MEGA_MENUS_MANAGE);

        return Inertia::render('SuperAdmin/MegaMenus/Edit', [
            'mode' => 'create',
            'menu' => $this->blankMenu(),
            'meta' => [
                'created_at' => null,
                'updated_at' => null,
                'created_by' => null,
                'updated_by' => null,
            ],
            ...$this->editorSharedPayload(),
        ]);
    }

    public function store(StoreMegaMenuRequest $request): RedirectResponse
    {
        $menu = $this->manager->create($request->sanitized(), $request->user()?->id);

        $this->logAudit($request, 'mega_menu.created', $menu, [
            'slug' => $menu->slug,
            'location' => $menu->display_location,
        ]);

        return redirect()
            ->route('superadmin.mega-menus.edit', $menu)
            ->with('success', 'Mega menu created.');
    }

    public function edit(Request $request, MegaMenu $megaMenu): Response
    {
        $this->authorizePermission($request, PlatformPermissions::MEGA_MENUS_MANAGE);

        $megaMenu->load(['createdBy:id,name,email', 'updatedBy:id,name,email']);

        return Inertia::render('SuperAdmin/MegaMenus/Edit', [
            'mode' => 'edit',
            'menu' => $this->manager->toPayload($megaMenu),
            'meta' => [
                'created_at' => $megaMenu->created_at?->toIso8601String(),
                'updated_at' => $megaMenu->updated_at?->toIso8601String(),
                'created_by' => $megaMenu->createdBy ? [
                    'id' => $megaMenu->createdBy->id,
                    'name' => $megaMenu->createdBy->name,
                    'email' => $megaMenu->createdBy->email,
                ] : null,
                'updated_by' => $megaMenu->updatedBy ? [
                    'id' => $megaMenu->updatedBy->id,
                    'name' => $megaMenu->updatedBy->name,
                    'email' => $megaMenu->updatedBy->email,
                ] : null,
            ],
            ...$this->editorSharedPayload($megaMenu),
        ]);
    }

    public function update(UpdateMegaMenuRequest $request, MegaMenu $megaMenu): RedirectResponse
    {
        $menu = $this->manager->update($megaMenu, $request->sanitized(), $request->user()?->id);

        $this->logAudit($request, 'mega_menu.updated', $menu, [
            'slug' => $menu->slug,
            'location' => $menu->display_location,
        ]);

        return redirect()->back()->with('success', 'Mega menu updated.');
    }

    public function destroy(Request $request, MegaMenu $megaMenu): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::MEGA_MENUS_MANAGE);

        $slug = $megaMenu->slug;
        $megaMenu->delete();

        $this->logAudit($request, 'mega_menu.deleted', null, [
            'slug' => $slug,
        ]);

        return redirect()->route('superadmin.mega-menus.index')->with('success', 'Mega menu deleted.');
    }

    public function duplicate(Request $request, MegaMenu $megaMenu): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::MEGA_MENUS_MANAGE);

        $copy = $this->manager->duplicate($megaMenu, $request->user()?->id);

        $this->logAudit($request, 'mega_menu.duplicated', $copy, [
            'source_id' => $megaMenu->id,
            'slug' => $copy->slug,
        ]);

        return redirect()->route('superadmin.mega-menus.edit', $copy)->with('success', 'Mega menu duplicated.');
    }

    public function activate(Request $request, MegaMenu $megaMenu): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::MEGA_MENUS_MANAGE);

        $menu = $this->manager->changeStatus($megaMenu, MegaMenuOptions::STATUS_ACTIVE, $request->user()?->id);

        $this->logAudit($request, 'mega_menu.activated', $menu, [
            'slug' => $menu->slug,
        ]);

        return redirect()->back()->with('success', 'Mega menu activated.');
    }

    public function deactivate(Request $request, MegaMenu $megaMenu): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::MEGA_MENUS_MANAGE);

        $menu = $this->manager->changeStatus($megaMenu, MegaMenuOptions::STATUS_INACTIVE, $request->user()?->id);

        $this->logAudit($request, 'mega_menu.deactivated', $menu, [
            'slug' => $menu->slug,
        ]);

        return redirect()->back()->with('success', 'Mega menu deactivated.');
    }

    public function reorder(ReorderMegaMenusRequest $request): RedirectResponse
    {
        $this->manager->reorder($request->validated('ids'));

        $this->logAudit($request, 'mega_menu.reordered', null, [
            'count' => count($request->validated('ids')),
        ]);

        return redirect()->back()->with('success', 'Mega menu order updated.');
    }

    public function preview(Request $request, MegaMenu $megaMenu): Response
    {
        $this->authorizePermission($request, PlatformPermissions::MEGA_MENUS_MANAGE);

        return Inertia::render('SuperAdmin/MegaMenus/Preview', [
            'menu' => $this->renderer->serialize($megaMenu, 'preview'),
            'dashboard_url' => route('superadmin.dashboard'),
            'index_url' => route('superadmin.mega-menus.index'),
            'edit_url' => route('superadmin.mega-menus.edit', $megaMenu),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function editorSharedPayload(?MegaMenu $menu = null): array
    {
        return [
            'choices' => $this->choicePayload(),
            'internal_page_options' => $this->internalPageOptions(),
            'asset_list_url' => route('superadmin.assets.list'),
            'asset_upload_url' => route('superadmin.assets.store'),
            'dashboard_url' => route('superadmin.dashboard'),
            'index_url' => route('superadmin.mega-menus.index'),
            'preview_url' => $menu ? route('superadmin.mega-menus.preview', $menu) : null,
            'activate_url' => $menu ? route('superadmin.mega-menus.activate', $menu) : null,
            'deactivate_url' => $menu ? route('superadmin.mega-menus.deactivate', $menu) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function choicePayload(): array
    {
        $labels = MegaMenuOptions::labels();

        $mapChoices = fn (array $values) => array_map(fn (string $value) => [
            'value' => $value,
            'label' => $labels[$value] ?? ucfirst(str_replace('_', ' ', $value)),
        ], $values);

        return [
            'statuses' => $mapChoices(MegaMenuOptions::statuses()),
            'display_locations' => $mapChoices(MegaMenuOptions::displayLocations()),
            'link_types' => $mapChoices(MegaMenuOptions::linkTypes()),
            'link_targets' => $mapChoices(MegaMenuOptions::linkTargets()),
            'panel_types' => $mapChoices(MegaMenuOptions::panelTypes()),
            'badge_variants' => $mapChoices(MegaMenuOptions::badgeVariants()),
            'block_types' => MegaMenuBlockRegistry::builderDefinitions(),
            'defaults' => [
                'menu_settings' => $this->sanitizer->defaultMenuSettings(),
                'item_settings' => $this->sanitizer->defaultItemSettings(),
                'column_settings' => $this->sanitizer->defaultColumnSettings(),
                'block_settings' => $this->sanitizer->defaultBlockSettings(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function internalPageOptions(): array
    {
        $options = [
            ['label' => 'Home', 'value' => route('welcome', [], false)],
            ['label' => 'Pricing', 'value' => route('pricing', [], false)],
            ['label' => 'Terms', 'value' => route('terms', [], false)],
            ['label' => 'Privacy', 'value' => route('privacy', [], false)],
            ['label' => 'Refund', 'value' => route('refund', [], false)],
        ];

        $pages = PlatformPage::query()
            ->orderBy('title')
            ->get(['slug', 'title'])
            ->map(fn (PlatformPage $page) => [
                'label' => 'Page: '.$page->title,
                'value' => route('public.pages.show', ['slug' => $page->slug], false),
            ])
            ->all();

        return [...$options, ...$pages];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankMenu(): array
    {
        $firstBlockType = MegaMenuBlockRegistry::builderDefinitions()[0]['type'] ?? 'navigation_group';

        return [
            'id' => null,
            'title' => '',
            'slug' => '',
            'status' => MegaMenuOptions::STATUS_DRAFT,
            'display_location' => MegaMenuOptions::LOCATION_HEADER,
            'custom_zone' => '',
            'description' => '',
            'css_classes' => '',
            'ordering' => 0,
            'settings' => $this->sanitizer->defaultMenuSettings(),
            'items' => [
                [
                    'id' => null,
                    'label' => 'Products',
                    'description' => '',
                    'link_type' => MegaMenuOptions::LINK_NONE,
                    'link_value' => null,
                    'link_target' => MegaMenuOptions::TARGET_SELF,
                    'panel_type' => MegaMenuOptions::PANEL_MEGA,
                    'icon' => 'grid-2x2',
                    'badge_text' => 'New',
                    'badge_variant' => MegaMenuOptions::BADGE_NEW,
                    'is_visible' => true,
                    'css_classes' => '',
                    'settings' => $this->sanitizer->defaultItemSettings(),
                    'children' => [],
                    'columns' => [
                        [
                            'id' => null,
                            'title' => 'Highlights',
                            'width' => '1.2fr',
                            'css_classes' => '',
                            'settings' => $this->sanitizer->defaultColumnSettings(),
                            'blocks' => [
                                [
                                    'id' => null,
                                    'type' => $firstBlockType,
                                    'title' => 'Start here',
                                    'css_classes' => '',
                                    'settings' => $this->sanitizer->defaultBlockSettings(),
                                    'payload' => MegaMenuBlockRegistry::defaultPayload($firstBlockType),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
