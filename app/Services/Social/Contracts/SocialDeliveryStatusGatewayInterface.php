<?php

namespace App\Services\Social\Contracts;

use App\Data\Social\ReadSocialDeliveryStatusData;
use App\Data\Social\SocialDeliveryStatusResultData;

interface SocialDeliveryStatusGatewayInterface
{
    public function readStatus(ReadSocialDeliveryStatusData $delivery): SocialDeliveryStatusResultData;
}
