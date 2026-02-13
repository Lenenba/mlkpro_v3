<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isClient()) {
            return (bool) $this->clientCustomer($user);
        }

        return $this->isInternalMember($user, $user->accountOwnerId());
    }

    public function view(User $user, Reservation $reservation): bool
    {
        if ($reservation->account_id !== $this->targetAccountId($user)) {
            return false;
        }

        if ($user->isClient()) {
            return $this->ownsReservation($user, $reservation);
        }

        return $this->isInternalMember($user, $reservation->account_id);
    }

    public function create(User $user): bool
    {
        if ($user->isClient()) {
            return (bool) $this->clientCustomer($user);
        }

        return $this->canManageInternal($user, $user->accountOwnerId());
    }

    public function update(User $user, Reservation $reservation): bool
    {
        if ($reservation->account_id !== $this->targetAccountId($user)) {
            return false;
        }

        if ($user->isClient()) {
            return false;
        }

        return $this->canManageInternal($user, $reservation->account_id);
    }

    public function updateStatus(User $user, Reservation $reservation): bool
    {
        if ($reservation->account_id !== $this->targetAccountId($user)) {
            return false;
        }

        if ($user->isClient()) {
            return false;
        }

        if ($this->canManageInternal($user, $reservation->account_id)) {
            return true;
        }

        $membership = TeamMember::query()
            ->forAccount($reservation->account_id)
            ->active()
            ->where('user_id', $user->id)
            ->first();

        if (!$membership) {
            return false;
        }

        return (int) $reservation->team_member_id === (int) $membership->id;
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $this->update($user, $reservation);
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        if ($reservation->account_id !== $this->targetAccountId($user)) {
            return false;
        }

        if ($user->isClient()) {
            return $this->ownsReservation($user, $reservation);
        }

        return $this->canManageInternal($user, $reservation->account_id);
    }

    public function reschedule(User $user, Reservation $reservation): bool
    {
        return $this->cancel($user, $reservation);
    }

    public function review(User $user, Reservation $reservation): bool
    {
        if ($reservation->account_id !== $this->targetAccountId($user)) {
            return false;
        }

        if (!$user->isClient()) {
            return false;
        }

        return $this->ownsReservation($user, $reservation);
    }

    public function manageSettings(User $user): bool
    {
        return $this->canManageInternal($user, $user->accountOwnerId());
    }

    private function targetAccountId(User $user): int
    {
        if ($user->isClient()) {
            return (int) ($this->clientCustomer($user)?->user_id ?? $user->accountOwnerId());
        }

        return $user->accountOwnerId();
    }

    private function ownsReservation(User $user, Reservation $reservation): bool
    {
        $customer = $this->clientCustomer($user);
        if (!$customer) {
            return false;
        }

        if ($reservation->client_user_id) {
            return (int) $reservation->client_user_id === (int) $user->id;
        }

        return (int) $reservation->client_id === (int) $customer->id;
    }

    private function isInternalMember(User $user, int $accountId): bool
    {
        if ($user->id === $accountId) {
            return true;
        }

        return (bool) TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->exists();
    }

    private function canManageInternal(User $user, int $accountId): bool
    {
        if ($user->id === $accountId) {
            return true;
        }

        $membership = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();

        if (!$membership) {
            return false;
        }

        if ($membership->role === 'admin') {
            return true;
        }

        return $membership->hasPermission('reservations.manage');
    }

    private function clientCustomer(User $user)
    {
        return $user->relationLoaded('customerProfile')
            ? $user->customerProfile
            : $user->customerProfile()->first();
    }
}
