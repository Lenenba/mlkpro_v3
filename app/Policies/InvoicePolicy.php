<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\TeamMember;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        if (! $this->hasInvoiceModule($user)) {
            return false;
        }

        if ($this->isOwnerActor($user)) {
            return true;
        }

        $membership = $this->membership($user);

        return (bool) $membership
            && (
                $membership->hasPermission('invoices.view')
                || $membership->hasPermission('invoices.create')
                || $membership->hasPermission('invoices.edit')
                || $membership->hasPermission('invoices.approve')
                || $membership->hasPermission('invoices.approve_high')
            );
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return (int) $user->accountOwnerId() === (int) $invoice->user_id;
    }

    public function create(User $user): bool
    {
        if (! $this->hasInvoiceModule($user)) {
            return false;
        }

        if ($this->isOwnerActor($user)) {
            return true;
        }

        $membership = $this->membership($user);

        return (bool) $membership && $membership->hasPermission('invoices.create');
    }

    public function transition(User $user, Invoice $invoice): bool
    {
        if (! $this->view($user, $invoice)) {
            return false;
        }

        if ($this->isOwnerActor($user)) {
            return true;
        }

        $membership = $this->membership($user);
        if (! $membership) {
            return false;
        }

        if ((int) $invoice->created_by_user_id === (int) $user->id) {
            return $membership->hasPermission('invoices.edit')
                || $membership->hasPermission('invoices.create');
        }

        return $membership->hasPermission('invoices.approve')
            || $membership->hasPermission('invoices.approve_high');
    }

    public function send(User $user, Invoice $invoice): bool
    {
        if (! $this->view($user, $invoice)) {
            return false;
        }

        if ($this->isOwnerActor($user)) {
            return true;
        }

        $membership = $this->membership($user);

        return (bool) $membership
            && (
                $membership->hasPermission('invoices.approve')
                || $membership->hasPermission('invoices.approve_high')
            );
    }

    private function hasInvoiceModule(User $user): bool
    {
        return ! $user->isClient() && $user->hasCompanyFeature('invoices');
    }

    private function isOwnerActor(User $user): bool
    {
        return $user->isAccountOwner()
            && (int) $user->id === (int) $user->accountOwnerId();
    }

    private function membership(User $user): ?TeamMember
    {
        return $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();
    }
}
