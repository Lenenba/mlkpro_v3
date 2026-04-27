<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Notifications\ProspectLifecycleNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Collection;

class ProspectNotificationService
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences
    ) {}

    public function notifyAssigned(LeadRequest $lead, ?User $actor = null, mixed $previousAssigneeId = null): void
    {
        $currentAssigneeId = is_numeric($lead->assigned_team_member_id) ? (int) $lead->assigned_team_member_id : null;
        $normalizedPreviousAssigneeId = is_numeric($previousAssigneeId) ? (int) $previousAssigneeId : null;

        if ($currentAssigneeId === null || $currentAssigneeId === $normalizedPreviousAssigneeId) {
            return;
        }

        $lead->loadMissing('assignee.user');
        $recipient = $lead->assignee?->user;

        if (! $recipient instanceof User) {
            return;
        }

        $this->dispatch(
            collect([$recipient]),
            new ProspectLifecycleNotification($lead, ProspectLifecycleNotification::EVENT_ASSIGNED, $actor?->name),
            $actor
        );
    }

    public function notifyConverted(LeadRequest $lead, ?User $actor = null): void
    {
        if (! $lead->isConvertedToCustomer()) {
            return;
        }

        $lead->loadMissing([
            'user',
            'assignee.user',
            'customer',
        ]);

        $this->dispatch(
            $this->stakeholders($lead),
            new ProspectLifecycleNotification(
                $lead,
                ProspectLifecycleNotification::EVENT_CONVERTED,
                $actor?->name,
                $this->customerDisplayName($lead->customer)
            ),
            $actor
        );
    }

    public function notifyCreated(LeadRequest $lead, ?User $actor = null): void
    {
        $lead->loadMissing('user');

        $this->dispatch(
            collect([$lead->user]),
            new ProspectLifecycleNotification($lead, ProspectLifecycleNotification::EVENT_CREATED, $actor?->name),
            $actor
        );

        $this->notifyAssigned($lead, $actor);
    }

    public function notifyLost(LeadRequest $lead, ?User $actor = null): void
    {
        if ($lead->status !== LeadRequest::STATUS_LOST) {
            return;
        }

        $lead->loadMissing('user', 'assignee.user');

        $this->dispatch(
            $this->stakeholders($lead),
            new ProspectLifecycleNotification($lead, ProspectLifecycleNotification::EVENT_LOST, $actor?->name),
            $actor
        );
    }

    /**
     * @param  Collection<int, User|null>  $recipients
     */
    private function dispatch(Collection $recipients, ProspectLifecycleNotification $notification, ?User $actor = null): void
    {
        $eligibleRecipients = $recipients
            ->filter(fn ($recipient) => $recipient instanceof User)
            ->reject(fn (User $recipient) => $actor && (int) $recipient->id === (int) $actor->id)
            ->unique('id')
            ->filter(fn (User $recipient) => $this->preferences->shouldNotify(
                $recipient,
                NotificationPreferenceService::CATEGORY_CRM,
                NotificationPreferenceService::CHANNEL_IN_APP
            ))
            ->values();

        foreach ($eligibleRecipients as $recipient) {
            NotificationDispatcher::send($recipient, $notification, [
                'lead_id' => $notification->lead->id,
                'event' => $notification->event,
                'recipient_id' => $recipient->id,
            ]);
        }
    }

    /**
     * @return Collection<int, User|null>
     */
    private function stakeholders(LeadRequest $lead): Collection
    {
        return collect([
            $lead->user,
            $lead->assignee?->user,
        ]);
    }

    private function customerDisplayName(?Customer $customer): ?string
    {
        if (! $customer) {
            return null;
        }

        $companyName = trim((string) ($customer->company_name ?? ''));
        if ($companyName !== '') {
            return $companyName;
        }

        $contactName = trim(implode(' ', array_filter([
            trim((string) ($customer->first_name ?? '')),
            trim((string) ($customer->last_name ?? '')),
        ])));

        if ($contactName !== '') {
            return $contactName;
        }

        $email = trim((string) ($customer->email ?? ''));

        return $email !== '' ? $email : null;
    }
}
