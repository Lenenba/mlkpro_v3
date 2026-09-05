<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;

final readonly class CustomerPortalAccessResult
{
    public function __construct(
        public Customer $customer,
        public ?User $portalUser,
        public bool $accessChanged,
        public bool $portalUserCreated,
        public bool $portalUserLinked,
        public bool $invitationRequired,
    ) {}
}
