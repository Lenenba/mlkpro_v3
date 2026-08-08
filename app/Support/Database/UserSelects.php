<?php

namespace App\Support\Database;

final class UserSelects
{
    public static function companyFeatureContext(): array
    {
        return [
            'id',
            'company_name',
            'company_type',
            'company_sector',
            'company_features',
            'company_logo',
            'company_timezone',
            'currency_code',
            'onboarding_completed_at',
            'selected_plan_key',
            'selected_billing_period',
            'trial_ends_at',
        ];
    }

    public static function companySummary(): array
    {
        return ['id', 'company_type', 'company_name', 'company_logo'];
    }

    public static function portalCompanyContext(): array
    {
        return [...self::companySummary(), 'company_fulfillment'];
    }

    public static function identity(): array
    {
        return ['id', 'name', 'email'];
    }
}
