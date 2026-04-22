<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccountConnection;

class LinkedInPagePlatformPublisher extends AbstractOauthPlatformPublisher
{
    public function key(): string
    {
        return SocialAccountConnection::PLATFORM_LINKEDIN;
    }

    public function label(): string
    {
        return 'LinkedIn Pages';
    }

    protected function targetType(): string
    {
        return 'organization';
    }

    protected function description(): string
    {
        return 'Share company announcements and campaign content on LinkedIn organization pages.';
    }

    protected function scopes(): array
    {
        return [
            'r_organization_admin',
            'r_organization_social',
            'w_organization_social',
        ];
    }

    protected function defaultAuthorizeUrl(): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization';
    }

    protected function defaultTokenUrl(): string
    {
        return 'https://www.linkedin.com/oauth/v2/accessToken';
    }
}
