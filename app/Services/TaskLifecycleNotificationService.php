<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;

class TaskLifecycleNotificationService
{
    public function __construct(
        private PushNotificationService $push,
        private SmsNotificationService $sms,
        private CompanyNotificationPreferenceService $companyPreferences,
        private WhatsappAlertMessageFormatter $whatsappFormatter,
    ) {}

    public function sendCancelled(Task $task, ?User $actor = null): void
    {
        $this->notify($task, 'cancelled', $actor);
    }

    public function sendOverdue(Task $task, ?User $actor = null): void
    {
        $this->notify($task, 'overdue', $actor);
    }

    public function sendRescheduled(Task $task, ?User $actor = null, ?string $previousDueDate = null, ?string $reason = null): void
    {
        $this->notify($task, 'rescheduled', $actor, $previousDueDate, $reason);
    }

    private function notify(Task $task, string $type, ?User $actor = null, ?string $previousDueDate = null, ?string $reason = null): void
    {
        $task->loadMissing([
            'account:id,name,email,phone_number,locale,company_name,company_notification_settings',
            'assignee.user:id,name,locale',
            'customer:id,user_id,portal_user_id,company_name,first_name,last_name,email,phone',
            'customer.user:id,locale',
            'customer.portalUser:id,locale',
            'work:id,job_title,number',
        ]);

        $owner = $task->account ?: User::query()
            ->select(['id', 'name', 'email', 'phone_number', 'locale', 'company_name', 'company_notification_settings'])
            ->find($task->account_id);

        if (! $owner) {
            return;
        }

        $this->notifyInternalRecipients($task, $owner, $type, $previousDueDate, $reason);
        $this->notifyExternalRecipients($task, $owner, $type, $actor, $previousDueDate, $reason);
    }

    private function notifyInternalRecipients(Task $task, User $owner, string $type, ?string $previousDueDate = null, ?string $reason = null): void
    {
        $recipients = $this->resolveInternalRecipients($task, $owner);
        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $user) {
            $message = $this->messageSet($type, $user->locale ?? $owner->locale, $task, $previousDueDate, $reason);
            $this->push->sendToUsers([$user->id], [
                'title' => $message['push_title'],
                'body' => $message['push_body'],
                'data' => [
                    'type' => match ($type) {
                        'cancelled' => 'task_cancelled',
                        'rescheduled' => 'task_rescheduled',
                        default => 'task_overdue',
                    },
                    'task_id' => $task->id,
                ],
            ]);
        }
    }

    private function notifyExternalRecipients(Task $task, User $owner, string $type, ?User $actor = null, ?string $previousDueDate = null, ?string $reason = null): void
    {
        $channels = $this->companyPreferences->taskUpdateChannels($owner);
        if (! array_filter($channels)) {
            return;
        }

        $customer = $task->customer;
        $customerSent = false;

        if ($customer instanceof Customer) {
            $customerSent = $this->deliverToCustomer($customer, $owner, $task, $type, $channels, $previousDueDate, $reason) || $customerSent;
        }

        if ($customerSent) {
            return;
        }

        $this->deliverToOwner($owner, $task, $type, $channels, $actor, $previousDueDate, $reason);
    }

    private function deliverToCustomer(Customer $customer, User $owner, Task $task, string $type, array $channels, ?string $previousDueDate = null, ?string $reason = null): bool
    {
        $locale = LocalePreference::forCustomer($customer, $owner);
        $message = $this->messageSet($type, $locale, $task, $previousDueDate, $reason);
        $details = $this->emailDetails($message, $task, $previousDueDate);

        $sent = false;
        if ((bool) ($channels[CompanyNotificationPreferenceService::CHANNEL_EMAIL] ?? false) && $customer->email) {
            $sent = NotificationDispatcher::send($customer, new ActionEmailNotification(
                $message['email_title'],
                $message['email_intro'],
                $details,
                null,
                null,
                $message['email_subject'],
                $message['email_note']
            ), [
                'task_id' => $task->id,
                'type' => $type,
            ]) || $sent;
        }

        if ((bool) ($channels[CompanyNotificationPreferenceService::CHANNEL_SMS] ?? false) && $customer->phone) {
            $sent = $this->sms->send($customer->phone, $message['sms']) || $sent;
        }

        if ((bool) ($channels[CompanyNotificationPreferenceService::CHANNEL_WHATSAPP] ?? false) && $customer->phone) {
            $sent = app(WhatsappNotificationService::class)->send($customer->phone, $this->whatsappFormatter->build(
                $owner->company_name ?: $owner->name ?: 'Malikia Pro',
                $message['email_title'],
                $message['email_intro'],
                $details
            )) || $sent;
        }

        return $sent;
    }

    private function deliverToOwner(User $owner, Task $task, string $type, array $channels, ?User $actor = null, ?string $previousDueDate = null, ?string $reason = null): bool
    {
        $locale = $owner->preferredLocale();
        $message = $this->messageSet($type, $locale, $task, $previousDueDate, $reason);
        $details = $this->emailDetails($message, $task, $previousDueDate);
        $actorName = $actor?->name;

        if ($actorName) {
            $details[] = [
                'label' => $message['actor_label'],
                'value' => $actorName,
            ];
        }

        $sent = false;
        if ((bool) ($channels[CompanyNotificationPreferenceService::CHANNEL_EMAIL] ?? false) && $owner->email) {
            $sent = NotificationDispatcher::sendToMail($owner->email, new ActionEmailNotification(
                $message['email_title'],
                $message['email_intro'],
                $details,
                route('task.show', $task),
                $message['action_label'],
                $message['email_subject'],
                $message['email_note']
            ), [
                'task_id' => $task->id,
                'type' => $type,
                'owner_id' => $owner->id,
            ]) || $sent;
        }

        if ((bool) ($channels[CompanyNotificationPreferenceService::CHANNEL_SMS] ?? false) && $owner->phone_number) {
            $sent = $this->sms->send($owner->phone_number, $message['sms']) || $sent;
        }

        if ((bool) ($channels[CompanyNotificationPreferenceService::CHANNEL_WHATSAPP] ?? false) && $owner->phone_number) {
            $sent = app(WhatsappNotificationService::class)->send($owner->phone_number, $this->whatsappFormatter->build(
                $owner->company_name ?: $owner->name ?: 'Malikia Pro',
                $message['email_title'],
                $message['email_intro'],
                $details,
                route('task.show', $task),
                $message['action_label']
            )) || $sent;
        }

        return $sent;
    }

    private function resolveInternalRecipients(Task $task, User $owner)
    {
        $users = collect([$owner]);

        $assignee = $task->assignee?->user;
        if ($assignee) {
            $users->push($assignee);
        }

        $admins = TeamMember::query()
            ->forAccount($owner->id)
            ->active()
            ->where('role', 'admin')
            ->with('user:id,name,locale')
            ->get()
            ->map(fn (TeamMember $member) => $member->user)
            ->filter();

        return $users->merge($admins)->filter()->unique('id')->values();
    }

    private function emailDetails(array $message, Task $task, ?string $previousDueDate = null): array
    {
        $details = [
            ['label' => $message['task_label'], 'value' => $task->title ?: 'Task #'.$task->id],
            ['label' => $message['status_label'], 'value' => $message['status_value']],
        ];

        if (($message['type'] ?? null) === 'rescheduled') {
            if ($previousDueDate) {
                $details[] = [
                    'label' => $message['previous_due_label'],
                    'value' => $previousDueDate,
                ];
            }

            if ($task->due_date) {
                $details[] = [
                    'label' => $message['new_due_label'],
                    'value' => $task->due_date->toDateString(),
                ];
            }
        } elseif ($task->due_date) {
            $details[] = [
                'label' => $message['due_label'],
                'value' => $task->due_date->toDateString(),
            ];
        }

        if ($task->assignee?->user?->name) {
            $details[] = [
                'label' => $message['assignee_label'],
                'value' => $task->assignee->user->name,
            ];
        }

        $reason = $message['reason_value'] ?? ($task->cancellation_reason ?: $task->delay_reason);
        if ($reason) {
            $details[] = [
                'label' => $message['reason_label'],
                'value' => $reason,
            ];
        }

        return $details;
    }

    private function messageSet(string $type, ?string $locale, Task $task, ?string $previousDueDate = null, ?string $reasonOverride = null): array
    {
        $code = strtolower((string) $locale);
        $lang = str_starts_with($code, 'fr')
            ? 'fr'
            : (str_starts_with($code, 'es') ? 'es' : 'en');
        $title = $task->title ?: 'Task #'.$task->id;
        $dueDate = $task->due_date?->toDateString();
        $reason = $reasonOverride ?: ($task->cancellation_reason ?: $task->delay_reason);

        $statusValue = match ([$type, $lang]) {
            ['cancelled', 'fr'] => 'Annulee',
            ['cancelled', 'es'] => 'Cancelada',
            ['cancelled', 'en'] => 'Cancelled',
            ['rescheduled', 'fr'] => 'Reportee',
            ['rescheduled', 'es'] => 'Reprogramada',
            ['rescheduled', 'en'] => 'Rescheduled',
            ['overdue', 'fr'] => 'En retard',
            ['overdue', 'es'] => 'Atrasada',
            default => 'Overdue',
        };

        if ($type === 'rescheduled') {
            return match ($lang) {
                'fr' => [
                    'type' => 'rescheduled',
                    'push_title' => 'Visite reportee',
                    'push_body' => "La visite \"{$title}\" a ete reportee.".($dueDate ? " Nouvelle date: {$dueDate}." : '').($reason ? " Motif: {$reason}." : ''),
                    'email_subject' => 'Visite reportee',
                    'email_title' => 'Visite reportee',
                    'email_intro' => "Votre visite \"{$title}\" a ete reportee.".($dueDate ? " Nouvelle date: {$dueDate}." : ''),
                    'email_note' => $reason ? "Motif: {$reason}" : null,
                    'sms' => "Visite reportee: {$title}".($dueDate ? " nouvelle date {$dueDate}" : '').($reason ? " - {$reason}" : ''),
                    'action_label' => 'Voir la tache',
                    'task_label' => 'Visite',
                    'status_label' => 'Statut',
                    'status_value' => $statusValue,
                    'due_label' => 'Date prevue',
                    'previous_due_label' => 'Ancienne date',
                    'new_due_label' => 'Nouvelle date',
                    'assignee_label' => 'Assigne',
                    'reason_label' => 'Motif du report',
                    'reason_value' => $reason,
                    'actor_label' => 'Par',
                ],
                'es' => [
                    'type' => 'rescheduled',
                    'push_title' => 'Visita reprogramada',
                    'push_body' => "La visita \"{$title}\" fue reprogramada.".($dueDate ? " Nueva fecha: {$dueDate}." : '').($reason ? " Motivo: {$reason}." : ''),
                    'email_subject' => 'Visita reprogramada',
                    'email_title' => 'Visita reprogramada',
                    'email_intro' => "Su visita \"{$title}\" fue reprogramada.".($dueDate ? " Nueva fecha: {$dueDate}." : ''),
                    'email_note' => $reason ? "Motivo: {$reason}" : null,
                    'sms' => "Visita reprogramada: {$title}".($dueDate ? " nueva fecha {$dueDate}" : '').($reason ? " - {$reason}" : ''),
                    'action_label' => 'Ver tarea',
                    'task_label' => 'Visita',
                    'status_label' => 'Estado',
                    'status_value' => $statusValue,
                    'due_label' => 'Fecha prevista',
                    'previous_due_label' => 'Fecha anterior',
                    'new_due_label' => 'Nueva fecha',
                    'assignee_label' => 'Asignado',
                    'reason_label' => 'Motivo de reprogramacion',
                    'reason_value' => $reason,
                    'actor_label' => 'Por',
                ],
                default => [
                    'type' => 'rescheduled',
                    'push_title' => 'Visit rescheduled',
                    'push_body' => "Visit \"{$title}\" was rescheduled.".($dueDate ? " New date: {$dueDate}." : '').($reason ? " Reason: {$reason}." : ''),
                    'email_subject' => 'Visit rescheduled',
                    'email_title' => 'Visit rescheduled',
                    'email_intro' => "Your visit \"{$title}\" was rescheduled.".($dueDate ? " New date: {$dueDate}." : ''),
                    'email_note' => $reason ? "Reason: {$reason}" : null,
                    'sms' => "Visit rescheduled: {$title}".($dueDate ? " new date {$dueDate}" : '').($reason ? " - {$reason}" : ''),
                    'action_label' => 'View task',
                    'task_label' => 'Visit',
                    'status_label' => 'Status',
                    'status_value' => $statusValue,
                    'due_label' => 'Planned date',
                    'previous_due_label' => 'Previous date',
                    'new_due_label' => 'New date',
                    'assignee_label' => 'Assignee',
                    'reason_label' => 'Reschedule reason',
                    'reason_value' => $reason,
                    'actor_label' => 'By',
                ],
            };
        }

        if ($type === 'cancelled') {
            return match ($lang) {
                'fr' => [
                    'push_title' => 'Tache annulee',
                    'push_body' => "La tache \"{$title}\" a ete annulee.".($reason ? " Motif: {$reason}." : ''),
                    'email_subject' => 'Annulation de tache',
                    'email_title' => 'Tache annulee',
                    'email_intro' => "La tache \"{$title}\" a ete annulee.".($dueDate ? " Date prevue: {$dueDate}." : ''),
                    'email_note' => $reason ? "Motif: {$reason}" : null,
                    'sms' => "Tache annulee: {$title}".($dueDate ? " ({$dueDate})" : '').($reason ? " - {$reason}" : ''),
                    'action_label' => 'Voir la tache',
                    'task_label' => 'Tache',
                    'status_label' => 'Statut',
                    'status_value' => $statusValue,
                    'due_label' => 'Date prevue',
                    'assignee_label' => 'Assigne',
                    'reason_label' => 'Motif',
                    'actor_label' => 'Par',
                ],
                'es' => [
                    'push_title' => 'Tarea cancelada',
                    'push_body' => "La tarea \"{$title}\" fue cancelada.".($reason ? " Motivo: {$reason}." : ''),
                    'email_subject' => 'Tarea cancelada',
                    'email_title' => 'Tarea cancelada',
                    'email_intro' => "La tarea \"{$title}\" fue cancelada.".($dueDate ? " Fecha prevista: {$dueDate}." : ''),
                    'email_note' => $reason ? "Motivo: {$reason}" : null,
                    'sms' => "Tarea cancelada: {$title}".($dueDate ? " ({$dueDate})" : '').($reason ? " - {$reason}" : ''),
                    'action_label' => 'Ver tarea',
                    'task_label' => 'Tarea',
                    'status_label' => 'Estado',
                    'status_value' => $statusValue,
                    'due_label' => 'Fecha prevista',
                    'assignee_label' => 'Asignado',
                    'reason_label' => 'Motivo',
                    'actor_label' => 'Por',
                ],
                default => [
                    'push_title' => 'Task cancelled',
                    'push_body' => "Task \"{$title}\" was cancelled.".($reason ? " Reason: {$reason}." : ''),
                    'email_subject' => 'Task cancelled',
                    'email_title' => 'Task cancelled',
                    'email_intro' => "Task \"{$title}\" was cancelled.".($dueDate ? " Planned date: {$dueDate}." : ''),
                    'email_note' => $reason ? "Reason: {$reason}" : null,
                    'sms' => "Task cancelled: {$title}".($dueDate ? " ({$dueDate})" : '').($reason ? " - {$reason}" : ''),
                    'action_label' => 'View task',
                    'task_label' => 'Task',
                    'status_label' => 'Status',
                    'status_value' => $statusValue,
                    'due_label' => 'Planned date',
                    'assignee_label' => 'Assignee',
                    'reason_label' => 'Reason',
                    'actor_label' => 'By',
                ],
            };
        }

        return match ($lang) {
            'fr' => [
                'push_title' => 'Tache en retard',
                'push_body' => "La tache \"{$title}\" est maintenant en retard.".($reason ? " Motif: {$reason}." : ''),
                'email_subject' => 'Tache en retard',
                'email_title' => 'Tache en retard',
                'email_intro' => "La tache \"{$title}\" n a pas ete terminee a temps.".($dueDate ? " Date prevue: {$dueDate}." : ''),
                'email_note' => $reason ? "Motif du retard: {$reason}" : null,
                'sms' => "Tache en retard: {$title}".($dueDate ? " ({$dueDate})" : '').($reason ? " - {$reason}" : ''),
                'action_label' => 'Voir la tache',
                'task_label' => 'Tache',
                'status_label' => 'Statut',
                'status_value' => $statusValue,
                'due_label' => 'Date prevue',
                'assignee_label' => 'Assigne',
                'reason_label' => 'Motif du retard',
                'actor_label' => 'Par',
            ],
            'es' => [
                'push_title' => 'Tarea atrasada',
                'push_body' => "La tarea \"{$title}\" ahora esta atrasada.".($reason ? " Motivo: {$reason}." : ''),
                'email_subject' => 'Tarea atrasada',
                'email_title' => 'Tarea atrasada',
                'email_intro' => "La tarea \"{$title}\" no se completo a tiempo.".($dueDate ? " Fecha prevista: {$dueDate}." : ''),
                'email_note' => $reason ? "Motivo del retraso: {$reason}" : null,
                'sms' => "Tarea atrasada: {$title}".($dueDate ? " ({$dueDate})" : '').($reason ? " - {$reason}" : ''),
                'action_label' => 'Ver tarea',
                'task_label' => 'Tarea',
                'status_label' => 'Estado',
                'status_value' => $statusValue,
                'due_label' => 'Fecha prevista',
                'assignee_label' => 'Asignado',
                'reason_label' => 'Motivo del retraso',
                'actor_label' => 'Por',
            ],
            default => [
                'push_title' => 'Task overdue',
                'push_body' => "Task \"{$title}\" is now overdue.".($reason ? " Reason: {$reason}." : ''),
                'email_subject' => 'Task overdue',
                'email_title' => 'Task overdue',
                'email_intro' => "Task \"{$title}\" was not completed on time.".($dueDate ? " Planned date: {$dueDate}." : ''),
                'email_note' => $reason ? "Delay reason: {$reason}" : null,
                'sms' => "Task overdue: {$title}".($dueDate ? " ({$dueDate})" : '').($reason ? " - {$reason}" : ''),
                'action_label' => 'View task',
                'task_label' => 'Task',
                'status_label' => 'Status',
                'status_value' => $statusValue,
                'due_label' => 'Planned date',
                'assignee_label' => 'Assignee',
                'reason_label' => 'Delay reason',
                'actor_label' => 'By',
            ],
        };
    }
}
