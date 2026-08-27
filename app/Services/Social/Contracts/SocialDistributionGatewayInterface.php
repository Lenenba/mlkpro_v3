<?php

namespace App\Services\Social\Contracts;

use App\Data\Social\CreateSocialDeliveryData;
use App\Data\Social\SocialDeliveryResultData;

interface SocialDistributionGatewayInterface
{
    public function createPost(CreateSocialDeliveryData $delivery): SocialDeliveryResultData;
}
