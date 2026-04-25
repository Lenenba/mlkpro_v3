<?php

namespace App\Models;

class Prospect extends Request
{
    protected $table = 'requests';

    public const MODULE_KEY = 'prospects';

    public const LEGACY_MODULE_KEY = 'requests';

    public const PERMISSION_VIEW = 'prospects.view';

    public const PERMISSION_CREATE = 'prospects.create';

    public const PERMISSION_EDIT = 'prospects.edit';

    public const PERMISSION_ASSIGN = 'prospects.assign';

    public const PERMISSION_CONVERT = 'prospects.convert';

    public const PERMISSION_MERGE = 'prospects.merge';

    public const PERMISSION_EXPORT = 'prospects.export';

    public const LEGACY_PERMISSION_VIEW = 'requests.view';

    public const LEGACY_PERMISSION_CREATE = 'requests.create';

    public const LEGACY_PERMISSION_EDIT = 'requests.edit';

    public const LEGACY_PERMISSION_ASSIGN = 'requests.assign';

    public const LEGACY_PERMISSION_CONVERT = 'requests.convert';

    public const LEGACY_PERMISSION_MERGE = 'requests.merge';

    public const LEGACY_PERMISSION_EXPORT = 'requests.export';

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'New',
        self::STATUS_CALL_REQUESTED => 'Call requested',
        self::STATUS_CONTACTED => 'Contacted',
        self::STATUS_QUALIFIED => 'Qualified',
        self::STATUS_QUOTE_SENT => 'Quote sent',
        self::STATUS_WON => 'Won',
        self::STATUS_LOST => 'Lost',
        self::STATUS_CONVERTED => 'Converted',
    ];

    public static function statusOptions(): array
    {
        return collect(self::STATUS_LABELS)
            ->map(fn (string $label, string $status) => [
                'id' => $status,
                'name' => $label,
            ])
            ->values()
            ->all();
    }

    public static function permissionAliases(): array
    {
        return [
            self::PERMISSION_VIEW => [self::LEGACY_PERMISSION_VIEW],
            self::PERMISSION_CREATE => [self::LEGACY_PERMISSION_CREATE],
            self::PERMISSION_EDIT => [self::LEGACY_PERMISSION_EDIT],
            self::PERMISSION_ASSIGN => [self::LEGACY_PERMISSION_ASSIGN],
            self::PERMISSION_CONVERT => [self::LEGACY_PERMISSION_CONVERT],
            self::PERMISSION_MERGE => [self::LEGACY_PERMISSION_MERGE],
            self::PERMISSION_EXPORT => [self::LEGACY_PERMISSION_EXPORT],
            self::LEGACY_PERMISSION_VIEW => [self::PERMISSION_VIEW],
            self::LEGACY_PERMISSION_CREATE => [self::PERMISSION_CREATE],
            self::LEGACY_PERMISSION_EDIT => [self::PERMISSION_EDIT],
            self::LEGACY_PERMISSION_ASSIGN => [self::PERMISSION_ASSIGN],
            self::LEGACY_PERMISSION_CONVERT => [self::PERMISSION_CONVERT],
            self::LEGACY_PERMISSION_MERGE => [self::PERMISSION_MERGE],
            self::LEGACY_PERMISSION_EXPORT => [self::PERMISSION_EXPORT],
        ];
    }
}
