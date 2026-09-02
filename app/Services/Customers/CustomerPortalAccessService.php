<?php

namespace App\Services\Customers;

use App\Enums\CustomerClientType;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomerPortalAccessService
{
    public function setAccess(Customer $customer, bool $enabled): CustomerPortalAccessResult
    {
        return DB::transaction(function () use ($customer, $enabled): CustomerPortalAccessResult {
            $lockedCustomer = Customer::query()
                ->whereKey($customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $enabled
                ? $this->enableAccess($lockedCustomer)
                : $this->disableAccess($lockedCustomer);
        });
    }

    public function sendInvitation(Customer $customer, User $accountOwner): bool
    {
        $customer->loadMissing('portalUser');
        $portalUser = $customer->portalUser;

        if (! $customer->portal_access || ! $portalUser) {
            throw ValidationException::withMessages([
                'portal_access' => ['Portal access must be enabled before an invitation can be sent.'],
            ]);
        }

        if ((int) $customer->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'portal_access' => ['The portal account does not belong to this workspace.'],
            ]);
        }

        if (! $portalUser->isClient()) {
            throw ValidationException::withMessages([
                'email' => ['The linked account is not a client portal account.'],
            ]);
        }

        $token = Password::broker()->createToken($portalUser);
        $notification = (new InviteUserNotification(
            $token,
            $accountOwner->company_name ?: config('app.name'),
            $accountOwner->company_logo_url,
            'client',
            $accountOwner->id,
        ))->afterCommit();

        return NotificationDispatcher::send($portalUser, $notification, [
            'customer_id' => $customer->id,
        ]);
    }

    private function enableAccess(Customer $customer): CustomerPortalAccessResult
    {
        $accessChanged = ! (bool) $customer->portal_access;
        $portalUserCreated = false;
        $portalUserLinked = false;
        $portalUser = $this->lockedLinkedPortalUser($customer);

        if (! $portalUser) {
            $portalUser = User::query()
                ->where('email', $customer->email)
                ->lockForUpdate()
                ->first();

            if ($portalUser) {
                $this->ensurePortalUserCanBeLinked($portalUser, $customer);
            } else {
                $portalUser = $this->createPortalUser($customer);
                $portalUserCreated = $portalUser->wasRecentlyCreated;
                if (! $portalUserCreated) {
                    $portalUser = User::query()
                        ->whereKey($portalUser->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $this->ensurePortalUserCanBeLinked($portalUser, $customer);
                }
            }

            $customer->portal_user_id = $portalUser->id;
            $portalUserLinked = true;
        } else {
            $this->ensurePortalUserCanBeLinked($portalUser, $customer);
        }

        $this->syncPortalUser($portalUser, $customer);
        $customer->portal_access = true;
        $customer->save();

        return new CustomerPortalAccessResult(
            customer: $customer,
            portalUser: $portalUser,
            accessChanged: $accessChanged,
            portalUserCreated: $portalUserCreated,
            portalUserLinked: $portalUserLinked,
            invitationRequired: $accessChanged || $portalUserCreated || $portalUserLinked,
        );
    }

    private function disableAccess(Customer $customer): CustomerPortalAccessResult
    {
        $accessChanged = (bool) $customer->portal_access;
        $portalUser = $this->lockedLinkedPortalUser($customer);

        if ($accessChanged && $portalUser?->isClient()) {
            $portalUser->tokens()->delete();
            Password::broker()->deleteToken($portalUser);
            $portalUser->forceFill([
                'remember_token' => Str::random(60),
            ])->save();
        }

        $customer->portal_access = false;
        $customer->save();

        return new CustomerPortalAccessResult(
            customer: $customer,
            portalUser: $portalUser,
            accessChanged: $accessChanged,
            portalUserCreated: false,
            portalUserLinked: false,
            invitationRequired: false,
        );
    }

    private function lockedLinkedPortalUser(Customer $customer): ?User
    {
        if (! $customer->portal_user_id) {
            return null;
        }

        return User::query()
            ->whereKey($customer->portal_user_id)
            ->lockForUpdate()
            ->first();
    }

    private function ensurePortalUserCanBeLinked(User $portalUser, Customer $customer): void
    {
        if (! $portalUser->isClient()) {
            throw ValidationException::withMessages([
                'email' => ['This email already belongs to a non-client account.'],
            ]);
        }

        $linkedCustomer = Customer::query()
            ->where('portal_user_id', $portalUser->id)
            ->lockForUpdate()
            ->first();

        if ($linkedCustomer && (int) $linkedCustomer->id !== (int) $customer->id) {
            throw ValidationException::withMessages([
                'email' => ['This client portal account is already linked to another customer.'],
            ]);
        }
    }

    private function createPortalUser(Customer $customer): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'client'],
            ['description' => 'Access to client functionalities']
        );

        return User::query()->firstOrCreate(
            ['email' => $customer->email],
            [
                'name' => $this->portalUserName($customer),
                'password' => Str::random(64),
                'role_id' => $role->id,
                'phone_number' => $customer->phone,
                'company_name' => $customer->client_type === CustomerClientType::COMPANY->value
                    ? $customer->company_name
                    : null,
                'must_change_password' => true,
            ]
        );
    }

    private function syncPortalUser(User $portalUser, Customer $customer): void
    {
        $emailAlreadyUsed = User::query()
            ->where('email', $customer->email)
            ->where('id', '!=', $portalUser->id)
            ->exists();

        if ($emailAlreadyUsed) {
            throw ValidationException::withMessages([
                'email' => ['This email already belongs to another portal account.'],
            ]);
        }

        $portalUser->forceFill([
            'name' => $this->portalUserName($customer),
            'email' => $customer->email,
            'phone_number' => $customer->phone,
            'company_name' => $customer->client_type === CustomerClientType::COMPANY->value
                ? $customer->company_name
                : null,
        ])->save();
    }

    private function portalUserName(Customer $customer): string
    {
        $name = Str::squish(trim((string) $customer->first_name).' '.trim((string) $customer->last_name));

        return $name !== ''
            ? $name
            : ($customer->company_name ?: $customer->email);
    }
}
