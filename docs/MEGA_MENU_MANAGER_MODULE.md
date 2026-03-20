# Mega Menu Manager Module

Last updated: 2026-03-19

## Overview
`Mega Menu Manager` is a super-admin module used to create, preview, activate, duplicate, reorder, and publish rich navigation menus.

The implementation is designed for a CMS / site-builder workflow:
- menus are stored as structured content
- admin users manage them from an interactive builder
- the frontend resolves menus by location or custom zone
- the renderer returns a safe fallback payload when no active menu exists

## Module Structure

### Backend
- `app/Http/Controllers/SuperAdmin/MegaMenuController.php`
  Handles admin list, builder, preview, status actions, duplication, deletion, and ordering.
- `app/Http/Requests/SuperAdmin/*MegaMenu*.php`
  Validates create, update, and reorder actions.
- `app/Services/MegaMenus/MegaMenuManagerService.php`
  Core write-side business logic.
- `app/Services/MegaMenus/MegaMenuPayloadSanitizer.php`
  Normalizes and validates nested builder payloads.
- `app/Services/MegaMenus/MegaMenuRenderer.php`
  Read-side serializer for frontend rendering.
- `app/Support/MegaMenuOptions.php`
  Enumerates supported statuses, locations, link types, targets, and panel types.
- `app/Support/MegaMenuBlockRegistry.php`
  Central registry for supported block types and their default payloads.

### Frontend
- `resources/js/Pages/SuperAdmin/MegaMenus/Index.vue`
  Admin list with search, filters, ordering, and actions.
- `resources/js/Pages/SuperAdmin/MegaMenus/Edit.vue`
  Three-panel builder:
  - left: structure tree
  - center: live preview
  - right: contextual settings
- `resources/js/Pages/SuperAdmin/MegaMenus/Preview.vue`
  Standalone preview page.
- `resources/js/Components/MegaMenu/MegaMenuDisplay.vue`
  Reusable frontend renderer.
- `resources/js/Components/MegaMenu/MegaMenuBlockRenderer.vue`
  Block-level renderer.
- `resources/js/Components/MegaMenu/MegaMenuBlockPayloadEditor.vue`
  Block payload editor used by the builder.
- `resources/js/utils/megaMenuBuilder.js`
  Builder helpers for creation, normalization, and submit serialization.

## Database Design

### `mega_menus`
Stores top-level menu metadata:
- `title`
- `slug`
- `status`
- `display_location`
- `custom_zone`
- `description`
- `css_classes`
- `ordering`
- `settings`
- `created_by`
- `updated_by`
- `published_at`
- timestamps

### `mega_menu_items`
Stores top-level items and classic dropdown children:
- `mega_menu_id`
- `parent_id`
- `label`
- `description`
- `link_type`
- `link_value`
- `link_target`
- `panel_type`
- `icon`
- `badge_text`
- `badge_variant`
- `is_visible`
- `css_classes`
- `settings`
- `sort_order`

### `mega_menu_columns`
Stores mega panel columns:
- `mega_menu_item_id`
- `title`
- `width`
- `css_classes`
- `settings`
- `sort_order`

### `mega_menu_blocks`
Stores content blocks inside each column:
- `mega_menu_column_id`
- `type`
- `title`
- `css_classes`
- `payload`
- `settings`
- `sort_order`

## Permission Model

Access is restricted with the existing platform admin permission system.

- Middleware:
  - `EnsurePlatformAdmin`
- Permission gate:
  - `PlatformPermissions::MEGA_MENUS_MANAGE`
- Allowed users:
  - superadmins
  - platform admins explicitly granted `mega_menus.manage`

The controller still validates permission on every action, even inside the super-admin route group.

## Rendering Flow

### Admin side
1. Builder payload is normalized by `MegaMenuPayloadSanitizer`.
2. `MegaMenuManagerService` persists menu metadata, items, columns, and blocks.
3. Activation automatically deactivates other active menus in the same location and custom zone.

### Frontend side
1. Public controllers call `MegaMenuRenderer`.
2. Menus can be resolved:
   - by location
   - by location + custom zone
   - by slug
3. The renderer serializes links, columns, blocks, and safe fallback data.
4. `MegaMenuDisplay.vue` consumes the payload and renders it in preview or frontend contexts.

### Current integrations
- `WelcomeController` resolves `header` + `welcome`
- `PublicPageController` resolves `header` + `public-pages`

If no menu is active, the renderer returns a fallback payload and the page-level fallback items remain usable.

## Adding a New Block Type Later

To introduce a new block type:

1. Add the definition to `app/Support/MegaMenuBlockRegistry.php`
   - label
   - description
   - default payload
2. Extend payload sanitizing in `MegaMenuPayloadSanitizer`
   - sanitize new payload shape
   - validate required fields
3. Add render support in:
   - `resources/js/Components/MegaMenu/MegaMenuBlockRenderer.vue`
4. Add builder form support in:
   - `resources/js/Components/MegaMenu/MegaMenuBlockPayloadEditor.vue`

The registry-driven structure means the builder can expose the block immediately once the backend definition exists.

## Notes on Architecture
- The nested structure is recreated on save instead of diffed. This keeps write logic simple and testable.
- Services own business rules; controllers stay thin.
- Validation is split between request rules and deep payload sanitizing to keep methods focused.
- No repository layer was added because Eloquent usage remains straightforward and localized.

## Advanced Improvements
- Scheduled publishing and automatic expiry windows
- Multilingual menu payloads and localized slugs
- Role-based or audience-based visibility at item or block level
- Click analytics and interaction heatmaps
- A/B testing for menu variants
- Tenant- or segment-specific menu personalization
