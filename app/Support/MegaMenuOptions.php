<?php

namespace App\Support;

class MegaMenuOptions
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const LOCATION_HEADER = 'header';
    public const LOCATION_FOOTER = 'footer';
    public const LOCATION_SIDEBAR = 'sidebar';
    public const LOCATION_CUSTOM = 'custom';

    public const LINK_INTERNAL_PAGE = 'internal_page';
    public const LINK_EXTERNAL_URL = 'external_url';
    public const LINK_ROUTE = 'route';
    public const LINK_ANCHOR = 'anchor';
    public const LINK_NONE = 'none';

    public const TARGET_SELF = '_self';
    public const TARGET_BLANK = '_blank';

    public const PANEL_LINK = 'link';
    public const PANEL_CLASSIC = 'classic';
    public const PANEL_MEGA = 'mega';

    public const BADGE_NEW = 'new';
    public const BADGE_HOT = 'hot';
    public const BADGE_FEATURED = 'featured';
    public const BADGE_PROMO = 'promo';
    public const BADGE_INFO = 'info';

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_ACTIVE,
            self::STATUS_INACTIVE,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function displayLocations(): array
    {
        return [
            self::LOCATION_HEADER,
            self::LOCATION_FOOTER,
            self::LOCATION_SIDEBAR,
            self::LOCATION_CUSTOM,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function linkTypes(): array
    {
        return [
            self::LINK_INTERNAL_PAGE,
            self::LINK_EXTERNAL_URL,
            self::LINK_ROUTE,
            self::LINK_ANCHOR,
            self::LINK_NONE,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function linkTargets(): array
    {
        return [
            self::TARGET_SELF,
            self::TARGET_BLANK,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function panelTypes(): array
    {
        return [
            self::PANEL_LINK,
            self::PANEL_CLASSIC,
            self::PANEL_MEGA,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function badgeVariants(): array
    {
        return [
            self::BADGE_NEW,
            self::BADGE_HOT,
            self::BADGE_FEATURED,
            self::BADGE_PROMO,
            self::BADGE_INFO,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            self::LOCATION_HEADER => 'Header',
            self::LOCATION_FOOTER => 'Footer',
            self::LOCATION_SIDEBAR => 'Sidebar',
            self::LOCATION_CUSTOM => 'Custom hook / zone',
            self::LINK_INTERNAL_PAGE => 'Internal page',
            self::LINK_EXTERNAL_URL => 'External URL',
            self::LINK_ROUTE => 'Route',
            self::LINK_ANCHOR => 'Anchor',
            self::LINK_NONE => 'No link',
            self::TARGET_SELF => 'Same tab',
            self::TARGET_BLANK => 'New tab',
            self::PANEL_LINK => 'Link only',
            self::PANEL_CLASSIC => 'Classic dropdown',
            self::PANEL_MEGA => 'Mega panel',
            self::BADGE_NEW => 'New',
            self::BADGE_HOT => 'Hot',
            self::BADGE_FEATURED => 'Featured',
            self::BADGE_PROMO => 'Promo',
            self::BADGE_INFO => 'Info',
        ];
    }
}
