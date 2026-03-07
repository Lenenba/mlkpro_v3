<?php

namespace App\Services\Assistant;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AssistantEntityResolver
{
    public function normalizeListFilters(array $interpretation): array
    {
        $filters = is_array($interpretation['filters'] ?? null) ? $interpretation['filters'] : [];
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));

        return [
            'search' => $search,
            'status' => $status,
        ];
    }

    public function resolveListLimit(array $filters, int $default = 10): int
    {
        $limit = $filters['limit'] ?? null;
        $limit = is_numeric($limit) ? (int) $limit : null;

        if (! $limit || $limit <= 0) {
            return $default;
        }

        return min($limit, 25);
    }

    public function resolveContextCustomer(int $accountId, array $context): ?Customer
    {
        $current = $context['current_customer'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return Customer::byUser($accountId)->whereKey($id)->first();
            }

            $email = trim((string) ($current['email'] ?? ''));
            if ($email !== '') {
                return Customer::byUser($accountId)->where('email', $email)->first();
            }
        }

        if (is_numeric($current)) {
            return Customer::byUser($accountId)->whereKey((int) $current)->first();
        }

        return null;
    }

    public function resolveCustomerAccount(User $user, bool $allowPos = false): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()
                ->select(['id', 'company_type', 'company_name', 'company_logo'])
                ->find($ownerId);

        if (! $owner) {
            return [null, null];
        }

        $accountId = $user->id;
        if ($owner->company_type === 'products') {
            if ($user->id !== $owner->id) {
                $membership = $user->relationLoaded('teamMembership')
                    ? $user->teamMembership
                    : $user->teamMembership()->first();
                $canManage = $membership?->hasPermission('sales.manage') ?? false;
                $canPos = $allowPos ? ($membership?->hasPermission('sales.pos') ?? false) : false;
                if (! $membership || (! $canManage && ! $canPos)) {
                    return [$owner, null];
                }
            }
            $accountId = $owner->id;
        }

        return [$owner, $accountId];
    }

    public function resolveCustomer(int $accountId, array $draft): ?Customer
    {
        $email = trim((string) ($draft['email'] ?? ''));
        $companyName = trim((string) ($draft['company_name'] ?? ''));
        $firstName = trim((string) ($draft['first_name'] ?? ''));
        $lastName = trim((string) ($draft['last_name'] ?? ''));

        if ($email !== '') {
            return Customer::byUser($accountId)->where('email', $email)->first();
        }

        if ($companyName !== '') {
            return Customer::byUser($accountId)
                ->whereRaw('LOWER(company_name) = ?', [strtolower($companyName)])
                ->first();
        }

        if ($firstName !== '' && $lastName !== '') {
            return Customer::byUser($accountId)
                ->whereRaw('LOWER(first_name) = ?', [strtolower($firstName)])
                ->whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])
                ->first();
        }

        return null;
    }

    public function resolveQuote(int $accountId, array $context, array $targets): ?Quote
    {
        $current = $context['current_quote'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return Quote::byUserWithArchived($accountId)->whereKey($id)->first();
            }

            $number = trim((string) ($current['number'] ?? ''));
            if ($number !== '') {
                return Quote::byUserWithArchived($accountId)->where('number', $number)->first();
            }
        }

        if (is_numeric($current)) {
            return Quote::byUserWithArchived($accountId)->whereKey((int) $current)->first();
        }

        if (! empty($targets['quote_id'])) {
            return Quote::byUserWithArchived($accountId)->whereKey((int) $targets['quote_id'])->first();
        }

        if (! empty($targets['quote_number'])) {
            return Quote::byUserWithArchived($accountId)->where('number', $targets['quote_number'])->first();
        }

        return null;
    }

    public function resolveWork(int $accountId, array $context, array $targets): ?Work
    {
        $current = $context['current_work'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return Work::byUser($accountId)->whereKey($id)->first();
            }

            $number = trim((string) ($current['number'] ?? ''));
            if ($number !== '') {
                return Work::byUser($accountId)->where('number', $number)->first();
            }
        }

        if (is_numeric($current)) {
            return Work::byUser($accountId)->whereKey((int) $current)->first();
        }

        $currentQuote = $context['current_quote'] ?? null;
        if (is_array($currentQuote)) {
            $workId = $currentQuote['work_id'] ?? null;
            if ($workId) {
                $work = Work::byUser($accountId)->whereKey((int) $workId)->first();
                if ($work) {
                    return $work;
                }
            }

            $quoteId = $currentQuote['id'] ?? null;
            if ($quoteId) {
                $work = Work::byUser($accountId)->where('quote_id', (int) $quoteId)->first();
                if ($work) {
                    return $work;
                }
            }
        }

        if (! empty($targets['work_id'])) {
            return Work::byUser($accountId)->whereKey((int) $targets['work_id'])->first();
        }

        if (! empty($targets['work_number'])) {
            return Work::byUser($accountId)->where('number', $targets['work_number'])->first();
        }

        if (! empty($targets['quote_id'])) {
            return Work::byUser($accountId)->where('quote_id', (int) $targets['quote_id'])->first();
        }

        return null;
    }

    public function resolveInvoice(int $accountId, array $context, array $targets): ?Invoice
    {
        $current = $context['current_invoice'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return Invoice::byUser($accountId)->whereKey($id)->first();
            }

            $number = trim((string) ($current['number'] ?? ''));
            if ($number !== '') {
                return Invoice::byUser($accountId)->where('number', $number)->first();
            }
        }

        if (is_numeric($current)) {
            return Invoice::byUser($accountId)->whereKey((int) $current)->first();
        }

        $currentWork = $context['current_work'] ?? null;
        if (is_array($currentWork)) {
            $workId = $currentWork['id'] ?? null;
            if ($workId) {
                $invoice = Invoice::byUser($accountId)->where('work_id', (int) $workId)->first();
                if ($invoice) {
                    return $invoice;
                }
            }
        }

        if (! empty($targets['invoice_id'])) {
            return Invoice::byUser($accountId)->whereKey((int) $targets['invoice_id'])->first();
        }

        if (! empty($targets['invoice_number'])) {
            return Invoice::byUser($accountId)->where('number', $targets['invoice_number'])->first();
        }

        if (! empty($targets['work_id'])) {
            return Invoice::byUser($accountId)->where('work_id', (int) $targets['work_id'])->first();
        }

        return null;
    }

    public function resolveTask(int $accountId, array $context, array $targets, array $taskData): ?Task
    {
        $query = Task::forAccount($accountId);

        $current = $context['current_task'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return $query->whereKey((int) $id)->first();
            }
        }

        if (is_numeric($current)) {
            return $query->whereKey((int) $current)->first();
        }

        if (! empty($targets['task_id'])) {
            return $query->whereKey((int) $targets['task_id'])->first();
        }

        $work = $this->resolveWork($accountId, $context, $targets);
        if ($work) {
            $query->where('work_id', $work->id);
        }

        $title = trim((string) ($taskData['title'] ?? ''));
        if ($title !== '') {
            $exact = (clone $query)
                ->whereRaw('LOWER(title) = ?', [strtolower($title)])
                ->first();
            if ($exact) {
                return $exact;
            }

            return (clone $query)
                ->where('title', 'like', '%'.$title.'%')
                ->orderByDesc('id')
                ->first();
        }

        if ($work) {
            return (clone $query)->orderByDesc('id')->first();
        }

        return null;
    }

    public function resolveRequest(int $accountId, array $targets, array $requestData, array $filters): ?LeadRequest
    {
        if (! empty($targets['request_id'])) {
            return LeadRequest::query()->where('user_id', $accountId)->find((int) $targets['request_id']);
        }

        $query = LeadRequest::query()->where('user_id', $accountId);
        $search = trim((string) ($filters['search'] ?? ''));
        $email = trim((string) ($requestData['contact_email'] ?? ''));
        $title = trim((string) ($requestData['title'] ?? ''));
        $serviceType = trim((string) ($requestData['service_type'] ?? ''));
        $contactName = trim((string) ($requestData['contact_name'] ?? ''));

        if ($email !== '') {
            return (clone $query)
                ->where('contact_email', $email)
                ->orderByDesc('id')
                ->first();
        }

        if ($title !== '') {
            $exact = (clone $query)
                ->whereRaw('LOWER(title) = ?', [strtolower($title)])
                ->first();
            if ($exact) {
                return $exact;
            }

            return (clone $query)
                ->where('title', 'like', '%'.$title.'%')
                ->orderByDesc('id')
                ->first();
        }

        if ($serviceType !== '') {
            return (clone $query)
                ->where('service_type', 'like', '%'.$serviceType.'%')
                ->orderByDesc('id')
                ->first();
        }

        if ($contactName !== '') {
            return (clone $query)
                ->where('contact_name', 'like', '%'.$contactName.'%')
                ->orderByDesc('id')
                ->first();
        }

        if ($search !== '') {
            return (clone $query)
                ->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', '%'.$search.'%')
                        ->orWhere('service_type', 'like', '%'.$search.'%')
                        ->orWhere('contact_name', 'like', '%'.$search.'%')
                        ->orWhere('contact_email', 'like', '%'.$search.'%')
                        ->orWhere('contact_phone', 'like', '%'.$search.'%');
                })
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    public function resolveChecklistItem(Work $work, array $targets, array $itemData): ?WorkChecklistItem
    {
        $query = $work->checklistItems()->orderBy('sort_order');

        if (! empty($targets['checklist_item_id'])) {
            return (clone $query)->whereKey((int) $targets['checklist_item_id'])->first();
        }

        $title = trim((string) ($itemData['title'] ?? ''));
        if ($title !== '') {
            $exact = (clone $query)
                ->whereRaw('LOWER(title) = ?', [strtolower($title)])
                ->first();
            if ($exact) {
                return $exact;
            }

            return (clone $query)
                ->where('title', 'like', '%'.$title.'%')
                ->first();
        }

        $items = (clone $query)->get();
        if ($items->count() === 1) {
            return $items->first();
        }

        $pending = $items->filter(fn ($item) => $item->status !== 'done')->values();
        if ($pending->count() === 1) {
            return $pending->first();
        }

        return null;
    }

    public function resolveTeamMemberIds(int $accountId, array $interpretation): array
    {
        $members = Arr::wrap($interpretation['team_members'] ?? []);
        $task = is_array($interpretation['task'] ?? null) ? $interpretation['task'] : [];
        $assignee = is_array($task['assignee'] ?? null) ? $task['assignee'] : [];
        if ($assignee) {
            $members[] = $assignee;
        }

        $ids = [];
        $baseQuery = TeamMember::query()->forAccount($accountId)->active();

        foreach ($members as $member) {
            if (is_numeric($member)) {
                $ids[] = (int) $member;

                continue;
            }

            if (is_string($member)) {
                $member = ['name' => $member];
            }

            if (! is_array($member)) {
                continue;
            }

            $email = trim((string) ($member['email'] ?? ''));
            $name = trim((string) ($member['name'] ?? ''));

            if ($email !== '') {
                $resolved = (clone $baseQuery)
                    ->whereHas('user', fn ($query) => $query->where('email', $email))
                    ->value('id');
                if ($resolved) {
                    $ids[] = (int) $resolved;

                    continue;
                }
            }

            if ($name !== '') {
                if (is_numeric($name)) {
                    $ids[] = (int) $name;

                    continue;
                }

                $resolved = (clone $baseQuery)
                    ->whereHas('user', fn ($query) => $query->where('name', 'like', '%'.$name.'%'))
                    ->value('id');
                if ($resolved) {
                    $ids[] = (int) $resolved;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function resolveSingleTeamMemberId(int $accountId, array $interpretation): ?int
    {
        $ids = $this->resolveTeamMemberIds($accountId, $interpretation);

        return $ids[0] ?? null;
    }

    public function normalizeWorkStatus(string $status): ?string
    {
        $value = strtolower(trim($status));
        if ($value === '') {
            return null;
        }

        $map = [
            'scheduled' => Work::STATUS_SCHEDULED,
            'planifie' => Work::STATUS_SCHEDULED,
            'to_schedule' => Work::STATUS_TO_SCHEDULE,
            'a_planifier' => Work::STATUS_TO_SCHEDULE,
            'en_route' => Work::STATUS_EN_ROUTE,
            'in_progress' => Work::STATUS_IN_PROGRESS,
            'tech_complete' => Work::STATUS_TECH_COMPLETE,
            'pending_review' => Work::STATUS_PENDING_REVIEW,
            'validated' => Work::STATUS_VALIDATED,
            'auto_validated' => Work::STATUS_AUTO_VALIDATED,
            'dispute' => Work::STATUS_DISPUTE,
            'closed' => Work::STATUS_CLOSED,
            'cancelled' => Work::STATUS_CANCELLED,
            'completed' => Work::STATUS_COMPLETED,
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (in_array($value, Work::STATUSES, true)) {
            return $value;
        }

        return null;
    }

    public function normalizeQuoteStatus(?string $status): ?string
    {
        $value = Str::ascii(strtolower(trim((string) $status)));
        if ($value === '') {
            return null;
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'draft' => 'draft',
            'brouillon' => 'draft',
            'sent' => 'sent',
            'envoye' => 'sent',
            'envoyee' => 'sent',
            'accepted' => 'accepted',
            'accepte' => 'accepted',
            'acceptee' => 'accepted',
            'declined' => 'declined',
            'refuse' => 'declined',
            'refusee' => 'declined',
            'rejected' => 'declined',
            'rejet' => 'declined',
            'archived' => 'archived',
            'archive' => 'archived',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (in_array($value, ['draft', 'sent', 'accepted', 'declined'], true)) {
            return $value;
        }

        return null;
    }

    public function normalizeInvoiceStatus(?string $status): ?string
    {
        $value = Str::ascii(strtolower(trim((string) $status)));
        if ($value === '') {
            return null;
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'draft' => 'draft',
            'brouillon' => 'draft',
            'sent' => 'sent',
            'envoye' => 'sent',
            'envoyee' => 'sent',
            'paid' => 'paid',
            'paye' => 'paid',
            'payee' => 'paid',
            'regle' => 'paid',
            'reglee' => 'paid',
            'partial' => 'partial',
            'partiel' => 'partial',
            'partielle' => 'partial',
            'overdue' => 'overdue',
            'en retard' => 'overdue',
            'retard' => 'overdue',
            'void' => 'void',
            'annule' => 'void',
            'annulee' => 'void',
            'awaiting acceptance' => 'awaiting_acceptance',
            'en attente' => 'awaiting_acceptance',
            'accepted' => 'accepted',
            'rejected' => 'rejected',
            'refuse' => 'rejected',
            'refusee' => 'rejected',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (in_array($value, Invoice::STATUSES, true)) {
            return $value;
        }

        return null;
    }

    public function normalizeTaskStatus(?string $status): ?string
    {
        $value = Str::ascii(strtolower(trim((string) $status)));
        if ($value === '') {
            return null;
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'todo' => 'todo',
            'to do' => 'todo',
            'a faire' => 'todo',
            'pending' => 'todo',
            'backlog' => 'todo',
            'in progress' => 'in_progress',
            'en cours' => 'in_progress',
            'progress' => 'in_progress',
            'done' => 'done',
            'termine' => 'done',
            'terminee' => 'done',
            'complete' => 'done',
            'completed' => 'done',
            'fait' => 'done',
            'finished' => 'done',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (in_array($value, Task::STATUSES, true)) {
            return $value;
        }

        return null;
    }

    public function normalizeChecklistStatus(?string $status): ?string
    {
        $value = Str::ascii(strtolower(trim((string) $status)));
        if ($value === '') {
            return null;
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'pending' => 'pending',
            'todo' => 'pending',
            'to do' => 'pending',
            'a faire' => 'pending',
            'en cours' => 'pending',
            'in progress' => 'pending',
            'done' => 'done',
            'termine' => 'done',
            'terminee' => 'done',
            'complete' => 'done',
            'completed' => 'done',
            'fait' => 'done',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        return null;
    }

    public function isValidDate(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function parseTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function findTaskScheduleConflict(
        int $accountId,
        int $assigneeId,
        ?string $dueDate,
        ?string $startTime,
        ?string $endTime,
        ?int $ignoreTaskId = null
    ): ?Task {
        if (! $assigneeId || ! $dueDate || ! $startTime) {
            return null;
        }

        $date = $this->parseDate($dueDate);
        $start = $this->parseTime($startTime);
        $end = $this->parseTime($endTime) ?: $start;
        if (! $date || ! $start) {
            return null;
        }

        $existingTasks = Task::query()
            ->forAccount($accountId)
            ->where('assigned_team_member_id', $assigneeId)
            ->whereDate('due_date', $date)
            ->whereNotNull('start_time')
            ->when($ignoreTaskId, fn ($query) => $query->where('id', '!=', $ignoreTaskId))
            ->get(['id', 'title', 'start_time', 'end_time']);

        $newStart = $this->timeToMinutes($start);
        $newEnd = $this->timeToMinutes($end);
        if ($newStart === null || $newEnd === null) {
            return null;
        }

        foreach ($existingTasks as $task) {
            $taskStart = $this->parseTime($task->start_time);
            if (! $taskStart) {
                continue;
            }
            $taskEnd = $this->parseTime($task->end_time) ?: $taskStart;
            $taskStartMin = $this->timeToMinutes($taskStart);
            $taskEndMin = $this->timeToMinutes($taskEnd);

            if ($taskStartMin === null || $taskEndMin === null) {
                continue;
            }

            $overlaps = $newStart <= $taskEndMin && $newEnd >= $taskStartMin;
            if ($overlaps) {
                return $task;
            }
        }

        return null;
    }

    private function timeToMinutes(string $time): ?int
    {
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        return ($hours * 60) + $minutes;
    }
}
