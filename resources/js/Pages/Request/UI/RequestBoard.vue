<script setup>
import { computed, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { humanizeDate } from '@/utils/date';
import { buildLeadScore, badgeClass } from '@/utils/leadScore';

const props = defineProps({
    requests: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const requestList = computed(() => (Array.isArray(props.requests) ? props.requests : []));

const statusLabel = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return t('requests.status.new');
        case 'REQ_CALL_REQUESTED':
            return t('requests.status.call_requested');
        case 'REQ_CONTACTED':
            return t('requests.status.contacted');
        case 'REQ_QUALIFIED':
            return t('requests.status.qualified');
        case 'REQ_QUOTE_SENT':
            return t('requests.status.quote_sent');
        case 'REQ_WON':
            return t('requests.status.won');
        case 'REQ_LOST':
            return t('requests.status.lost');
        case 'REQ_CONVERTED':
            return t('requests.status.converted');
        default:
            return status || t('requests.labels.unknown_status');
    }
};

const statusPillClass = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'REQ_CALL_REQUESTED':
            return 'bg-cyan-100 text-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-300';
        case 'REQ_CONTACTED':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
        case 'REQ_QUALIFIED':
            return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300';
        case 'REQ_QUOTE_SENT':
        case 'REQ_CONVERTED':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-300';
        case 'REQ_WON':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'REQ_LOST':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const triageQueueLabel = (queue) => {
    switch (queue) {
        case 'new':
            return t('requests.triage.queues.new');
        case 'due_soon':
            return t('requests.triage.queues.due_soon');
        case 'stale':
            return t('requests.triage.queues.stale');
        case 'breached':
            return t('requests.triage.queues.breached');
        case 'active':
            return t('requests.triage.queues.active');
        case 'closed':
            return t('requests.triage.queues.closed');
        default:
            return queue || t('requests.triage.queues.unknown');
    }
};

const triageQueueClass = (queue) => {
    switch (queue) {
        case 'new':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'due_soon':
            return 'bg-cyan-100 text-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-300';
        case 'stale':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-500/10 dark:text-orange-300';
        case 'breached':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        case 'active':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const triageRiskLabel = (riskLevel) => {
    switch (riskLevel) {
        case 'critical':
            return t('requests.triage.risk_levels.critical');
        case 'high':
            return t('requests.triage.risk_levels.high');
        case 'medium':
            return t('requests.triage.risk_levels.medium');
        case 'low':
            return t('requests.triage.risk_levels.low');
        case 'closed':
            return t('requests.triage.risk_levels.closed');
        default:
            return riskLevel || t('requests.triage.risk_levels.unknown');
    }
};

const triageRiskClass = (riskLevel) => {
    switch (riskLevel) {
        case 'critical':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        case 'high':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'medium':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
        case 'low':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const triagePriorityClass = (priority) => {
    const value = Number(priority || 0);

    if (value >= 90) {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300';
    }

    if (value >= 70) {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (value > 0) {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
};

const triageCardClass = (lead) => {
    switch (lead?.triage_queue) {
        case 'breached':
            return 'border-rose-200 bg-rose-50/40 shadow-rose-100/40 dark:border-rose-500/30 dark:bg-rose-500/5';
        case 'due_soon':
            return 'border-cyan-200 bg-cyan-50/40 shadow-cyan-100/40 dark:border-cyan-500/30 dark:bg-cyan-500/5';
        case 'stale':
            return 'border-amber-200 bg-amber-50/30 shadow-amber-100/40 dark:border-amber-500/30 dark:bg-amber-500/5';
        default:
            return 'border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900';
    }
};

const boardStatuses = computed(() => {
    if (props.statuses?.length) {
        return props.statuses.map((status) => String(status.id));
    }
    return ['REQ_NEW', 'REQ_CALL_REQUESTED', 'REQ_CONTACTED', 'REQ_QUALIFIED', 'REQ_QUOTE_SENT', 'REQ_WON', 'REQ_LOST'];
});

const boardLeads = ref({});

const syncBoardLeads = () => {
    const grouped = {};
    const incomingMap = {};
    const fallbackStatus = boardStatuses.value[0] || 'REQ_NEW';

    boardStatuses.value.forEach((status) => {
        grouped[status] = [];
        incomingMap[status] = new Map();
    });

    requestList.value.forEach((lead) => {
        const status = boardStatuses.value.includes(lead?.status) ? lead.status : fallbackStatus;
        if (!incomingMap[status]) {
            return;
        }
        incomingMap[status].set(lead.id, lead);
    });

    boardStatuses.value.forEach((status) => {
        const ordered = [];
        const existing = boardLeads.value?.[status] || [];

        existing.forEach((lead) => {
            const match = incomingMap[status].get(lead.id);
            if (match) {
                ordered.push(match);
                incomingMap[status].delete(lead.id);
            }
        });

        incomingMap[status].forEach((lead) => {
            ordered.push(lead);
        });

        grouped[status] = ordered;
    });

    boardLeads.value = grouped;
};

watch([requestList, boardStatuses], syncBoardLeads, { deep: true, immediate: true });

const formatDate = (value) => humanizeDate(value);
const formatAbsoluteDate = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleString();
};

const displayCustomer = (lead) =>
    lead?.customer?.company_name ||
    `${lead?.customer?.first_name || ''} ${lead?.customer?.last_name || ''}`.trim() ||
    lead?.contact_name ||
    t('requests.labels.unknown_customer');

const isClosedStatus = (status) => ['REQ_WON', 'REQ_LOST'].includes(status);
const isOverdue = (lead) => {
    if (!lead?.next_follow_up_at || isClosedStatus(lead?.status)) {
        return false;
    }
    const dueDate = new Date(lead.next_follow_up_at);
    if (Number.isNaN(dueDate.getTime())) {
        return false;
    }
    return dueDate.getTime() < Date.now();
};

const dragInProgress = ref(false);
const lastDragAt = ref(0);

const handleBoardStart = () => {
    dragInProgress.value = true;
};

const handleBoardEnd = () => {
    lastDragAt.value = Date.now();
    dragInProgress.value = false;
};

const buildStatusPayload = (lead, status) => {
    if (status !== 'REQ_LOST') {
        return { status };
    }
    const existing = lead?.lost_reason || '';
    const reason = window.prompt(t('requests.bulk.lost_reason_prompt'), existing);
    if (!reason) {
        return null;
    }
    return { status, lost_reason: reason };
};

const handleBoardChange = (status, event) => {
    if (!event?.added?.element) {
        return;
    }
    const lead = event.added.element;
    if (!lead || lead.status === status) {
        return;
    }
    const payload = buildStatusPayload(lead, status);
    if (!payload) {
        syncBoardLeads();
        return;
    }
    lead.status = status;
    router.put(route('request.update', lead.id), payload, {
        preserveScroll: true,
        only: ['requests', 'stats', 'flash'],
    });
};

const canOpenCard = () => !(dragInProgress.value || Date.now() - lastDragAt.value < 200);

const scoreInfo = (lead) => buildLeadScore(lead, t);
</script>

<template>
    <div class="overflow-x-auto">
        <div class="flex gap-4 min-w-[900px]">
            <div
                v-for="status in boardStatuses"
                :key="status"
                class="flex-1 min-w-[260px] rounded-sm border border-stone-200 bg-stone-50/60 p-3 dark:border-neutral-700 dark:bg-neutral-900/60"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                        {{ statusLabel(status) }}
                    </div>
                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ boardLeads[status]?.length || 0 }}
                    </span>
                </div>

                <draggable
                    :list="boardLeads[status]"
                    group="request-board"
                    item-key="id"
                    ghost-class="task-drag-ghost"
                    chosen-class="task-drag-chosen"
                    drag-class="task-drag-dragging"
                    class="mt-3 flex flex-col gap-3 min-h-[120px]"
                    @start="handleBoardStart"
                    @end="handleBoardEnd"
                    @change="handleBoardChange(status, $event)"
                >
                    <template #item="{ element }">
                        <div
                            class="rounded-sm border p-3 shadow-sm transition hover:border-stone-300 hover:shadow-md"
                            :class="triageCardClass(element)"
                            :data-testid="`request-board-card-${element.id}`"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <Link
                                        :href="route('request.show', element.id)"
                                        class="text-sm font-semibold text-stone-800 hover:text-emerald-600 dark:text-neutral-100"
                                        @click="(event) => { if (!canOpenCard()) event.preventDefault(); }"
                                    >
                                        {{ element.title || element.service_type || $t('requests.labels.request_number', { id: element.id }) }}
                                    </Link>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <span
                                            v-if="element.triage_queue"
                                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="triageQueueClass(element.triage_queue)"
                                            :data-testid="`request-board-queue-${element.id}`"
                                        >
                                            {{ triageQueueLabel(element.triage_queue) }}
                                        </span>
                                        <span
                                            class="rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                            :class="triagePriorityClass(element.triage_priority)"
                                        >
                                            {{ $t('requests.triage.priority_short', { value: element.triage_priority || 0 }) }}
                                        </span>
                                    </div>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusPillClass(element.status)">
                                    {{ statusLabel(element.status) }}
                                </span>
                            </div>

                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ displayCustomer(element) }}
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ $t('requests.badges.score') }} {{ scoreInfo(element).score }}
                                </span>
                                <span
                                    v-for="badge in scoreInfo(element).badges"
                                    :key="badge.key + badge.label + element.id"
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="badgeClass(badge.tone)"
                                >
                                    {{ badge.label }}
                                </span>
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span
                                    v-if="element.risk_level"
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="triageRiskClass(element.risk_level)"
                                >
                                    {{ triageRiskLabel(element.risk_level) }}
                                </span>
                                <span
                                    v-if="element.days_since_activity !== null
                                        && element.days_since_activity !== undefined
                                        && Number(element.days_since_activity) > 0"
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                                >
                                    {{ $t('requests.triage.inactive_days', { count: element.days_since_activity }) }}
                                </span>
                            </div>

                            <div class="mt-3 flex flex-col gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <div class="flex items-center justify-between">
                                    <span>{{ $t('requests.table.follow_up') }}</span>
                                    <span
                                        v-if="element.next_follow_up_at"
                                        :class="isOverdue(element) ? 'text-rose-600 dark:text-rose-400' : 'text-stone-700 dark:text-neutral-200'"
                                        :title="formatAbsoluteDate(element.next_follow_up_at)"
                                    >
                                        {{ formatDate(element.next_follow_up_at) }}
                                    </span>
                                    <span v-else>{{ $t('requests.labels.no_follow_up') }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>{{ $t('requests.table.assignee') }}</span>
                                    <span class="text-stone-700 dark:text-neutral-200">
                                        {{ element.assignee?.user?.name || element.assignee?.name || $t('requests.labels.unassigned') }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>{{ $t('requests.table.created') }}</span>
                                    <span class="text-stone-700 dark:text-neutral-200">
                                        {{ formatDate(element.created_at) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template #footer>
                        <div
                            v-if="!boardLeads[status]?.length"
                            class="rounded-sm border border-dashed border-stone-200 bg-white/70 p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900/40 dark:text-neutral-400"
                        >
                            {{ $t('requests.board.empty') }}
                        </div>
                    </template>
                </draggable>
            </div>
        </div>
    </div>
</template>
