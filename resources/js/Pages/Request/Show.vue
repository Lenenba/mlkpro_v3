<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/UI/Modal.vue';
import SalesActivityPanel from '@/Components/CRM/SalesActivityPanel.vue';
import ProspectInteractionTimeline from '@/Components/Prospects/ProspectInteractionTimeline.vue';
import { humanizeDate } from '@/utils/date';
import { formatBytes } from '@/utils/media';
import { buildLeadScore, badgeClass } from '@/utils/leadScore';
import {
    prospectCompanyLabel,
    prospectConsentLabel,
    prospectCustomerLabel,
    prospectIsAnonymized,
    prospectPrimaryLabel,
    prospectPriorityLabel,
    prospectRequestTypeLabel,
    prospectSourceLabel,
} from '@/utils/prospectPresentation';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    lead: {
        type: Object,
        required: true,
    },
    activity: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    assignees: {
        type: Array,
        default: () => [],
    },
    duplicates: {
        type: Array,
        default: () => [],
    },
    campaignOrigin: {
        type: Object,
        default: null,
    },
    canLogSalesActivity: {
        type: Boolean,
        default: false,
    },
    salesActivityQuickActions: {
        type: Array,
        default: () => [],
    },
    salesActivityManualActions: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

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

const statusClass = (status) => {
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

const titleForLead = computed(() => prospectPrimaryLabel(props.lead, t));
const displayCustomer = computed(() => prospectCustomerLabel(props.lead, t));
const companyLabel = computed(() => prospectCompanyLabel(props.lead, t));
const sourceLabel = computed(() => prospectSourceLabel(props.lead?.channel, t));
const requestTypeLabel = computed(() => prospectRequestTypeLabel(props.lead, t));
const priorityLabel = computed(() => prospectPriorityLabel(props.lead?.triage_priority, t));
const contactConsentLabel = computed(() => prospectConsentLabel(props.lead?.meta?.contact_consent, t));
const marketingConsentLabel = computed(() => prospectConsentLabel(props.lead?.meta?.marketing_consent, t));
const lastActivityLabel = computed(() => formatDate(props.lead?.last_activity_at || props.lead?.created_at));
const nextActionLabel = computed(() => formatDate(props.lead?.next_follow_up_at));
const isArchived = computed(() => Boolean(props.lead?.archived_at));
const isAnonymized = computed(() => prospectIsAnonymized(props.lead));
const archivedByLabel = computed(() => props.lead?.archived_by?.name || t('requests.activity.author_fallback'));
const anonymizedMeta = computed(() => props.lead?.meta?.privacy || {});
const anonymizedAtLabel = computed(() => formatDate(anonymizedMeta.value?.anonymized_at));
const anonymizationReason = computed(() => anonymizedMeta.value?.anonymization_reason || '');
const statusHistoryItems = computed(() => Array.isArray(props.lead?.status_histories) ? props.lead.status_histories : []);
const prospectInteractionItems = ref(Array.isArray(props.lead?.prospect_interactions) ? [...props.lead.prospect_interactions] : []);

const hasMedia = computed(() => Array.isArray(props.lead?.media) && props.lead.media.length > 0);
const hasTasks = computed(() => Array.isArray(props.lead?.tasks) && props.lead.tasks.length > 0);
const openTaskStatuses = ['todo', 'in_progress'];
const taskPriorityWeights = {
    urgent: 0,
    high: 1,
    normal: 2,
    low: 3,
};
const prospectTaskFilter = ref('all');

watch(
    () => props.lead?.prospect_interactions,
    (items) => {
        prospectInteractionItems.value = Array.isArray(items) ? [...items] : [];
    },
    { deep: true }
);

const closeOverlay = (selector) => {
    if (typeof window === 'undefined' || !window.HSOverlay) {
        return;
    }
    window.HSOverlay.close(selector);
};

const addressLabel = computed(() => {
    if (isAnonymized.value) {
        return t('requests.show.anonymized_address');
    }

    const parts = [
        props.lead?.street1,
        props.lead?.street2,
        props.lead?.city,
        props.lead?.state,
        props.lead?.postal_code,
        props.lead?.country,
    ].filter(Boolean);

    return parts.length ? parts.join(', ') : t('requests.show.no_address');
});

const isClosedStatus = (status) => ['REQ_WON', 'REQ_LOST', 'REQ_CONVERTED'].includes(status);
const isOverdue = (lead) => {
    if (!lead?.next_follow_up_at || isClosedStatus(lead?.status) || isArchived.value) {
        return false;
    }
    const dueDate = new Date(lead.next_follow_up_at);
    if (Number.isNaN(dueDate.getTime())) {
        return false;
    }
    return dueDate.getTime() < Date.now();
};

const contactPhone = computed(() => (isAnonymized.value ? '' : (props.lead?.contact_phone || props.lead?.customer?.phone || '')));
const contactEmail = computed(() => (isAnonymized.value ? '' : (props.lead?.contact_email || props.lead?.customer?.email || '')));

const normalizePhone = (value) => String(value || '').replace(/\D/g, '');
const whatsAppLink = computed(() => {
    const digits = normalizePhone(contactPhone.value);
    return digits ? `https://wa.me/${digits}` : null;
});

const archiveLead = () => {
    if (isArchived.value) {
        return;
    }

    if (!confirm(t('requests.actions.archive_confirm'))) {
        return;
    }

    router.patch(route('prospects.archive', props.lead.id), {}, {
        preserveScroll: true,
    });
};

const restoreLead = () => {
    if (!isArchived.value || isAnonymized.value) {
        return;
    }

    router.post(route('prospects.restore', props.lead.id), {}, {
        preserveScroll: true,
    });
};

const anonymizeLead = () => {
    if (!isArchived.value || isAnonymized.value) {
        return;
    }

    if (!confirm(t('requests.actions.anonymize_confirm'))) {
        return;
    }

    const reason = window.prompt(t('requests.actions.anonymize_reason_prompt'), '') ?? null;
    if (reason === null) {
        return;
    }

    router.patch(route('prospects.anonymize', props.lead.id), {
        anonymization_reason: reason.trim() || null,
    }, {
        preserveScroll: true,
    });
};

const noteForm = useForm({
    body: '',
});

const submitNote = () => {
    if (isArchived.value || noteForm.processing) {
        return;
    }
    noteForm.post(route('prospects.notes.store', props.lead.id), {
        preserveScroll: true,
        onSuccess: () => {
            noteForm.reset();
        },
    });
};

const deleteNote = (note) => {
    if (isArchived.value || !note?.id) {
        return;
    }
    if (!confirm(t('requests.notes.delete_confirm'))) {
        return;
    }
    router.delete(route('prospects.notes.destroy', { lead: props.lead.id, note: note.id }), {
        preserveScroll: true,
    });
};

const mediaForm = useForm({
    file: null,
});
const mediaInputRef = ref(null);

const handleMediaFile = (event) => {
    if (isArchived.value) {
        if (event.target) {
            event.target.value = '';
        }
        mediaForm.file = null;
        return;
    }

    const file = event.target.files?.[0] || null;
    mediaForm.clearErrors('file');
    if (!file) {
        mediaForm.file = null;
        if (event.target) {
            event.target.value = '';
        }
        return;
    }
    const allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) {
        mediaForm.setError('file', t('requests.media.invalid_type'));
        mediaForm.file = null;
        if (event.target) {
            event.target.value = '';
        }
        return;
    }
    if (file.size > 10 * 1024 * 1024) {
        mediaForm.setError('file', t('requests.media.too_large'));
        mediaForm.file = null;
        if (event.target) {
            event.target.value = '';
        }
        return;
    }
    mediaForm.file = file;
};

const submitMedia = () => {
    if (isArchived.value || mediaForm.processing || !mediaForm.file) {
        return;
    }
    mediaForm.post(route('prospects.media.store', props.lead.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            mediaForm.reset();
            if (mediaInputRef.value) {
                mediaInputRef.value.value = '';
            }
            closeOverlay('#request-media-modal');
        },
    });
};

const deleteMedia = (media) => {
    if (isArchived.value || !media?.id) {
        return;
    }
    if (!confirm(t('requests.media.delete_confirm'))) {
        return;
    }
    router.delete(route('prospects.media.destroy', { lead: props.lead.id, media: media.id }), {
        preserveScroll: true,
    });
};

const taskForm = useForm({
    title: '',
    description: '',
    due_date: '',
    priority: 'normal',
    assigned_team_member_id: props.lead?.assigned_team_member_id ? String(props.lead.assigned_team_member_id) : '',
    status: 'todo',
    standalone: true,
    request_id: props.lead?.id,
});

const submitTask = () => {
    if (isArchived.value || taskForm.processing) {
        return;
    }

    taskForm.transform((data) => ({
        ...data,
        assigned_team_member_id: data.assigned_team_member_id ? Number(data.assigned_team_member_id) : null,
        request_id: props.lead.id,
        standalone: true,
        status: data.status || 'todo',
    })).post(route('task.store'), {
        preserveScroll: true,
        onSuccess: () => {
            taskForm.reset('title', 'description', 'due_date', 'priority');
            taskForm.priority = 'normal';
            closeOverlay('#request-task-modal');
        },
    });
};

const taskStatusLabel = (status) => {
    const key = `tasks.status.${status}`;
    const label = t(key);

    return label === key ? (status || '-') : label;
};

const taskStatusClass = (status) => {
    switch (status) {
        case 'todo':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        case 'in_progress':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
        case 'done':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'cancelled':
            return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};
const taskPriorityLabel = (priority) => {
    const key = `tasks.priority.${priority || 'normal'}`;
    const label = t(key);

    return label === key ? (priority || 'normal') : label;
};
const taskPriorityClass = (priority) => {
    switch (priority) {
        case 'urgent':
            return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
        case 'high':
            return 'bg-orange-100 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300';
        case 'low':
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
        case 'normal':
        default:
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
    }
};
const normalizeTaskDateKey = (value) => {
    if (!value) {
        return null;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};
const todayTaskDateKey = () => normalizeTaskDateKey(new Date());
const isTaskOpen = (task) => openTaskStatuses.includes(task?.status);
const isTaskDueToday = (task) => isTaskOpen(task) && normalizeTaskDateKey(task?.due_date) === todayTaskDateKey();
const isTaskOverdue = (task) => {
    const dueDateKey = normalizeTaskDateKey(task?.due_date);

    return Boolean(dueDateKey) && isTaskOpen(task) && dueDateKey < todayTaskDateKey();
};
const leadTaskSortKey = (task) => normalizeTaskDateKey(task?.due_date) || '9999-12-31';
const leadTasks = computed(() => {
    const items = Array.isArray(props.lead?.tasks) ? [...props.lead.tasks] : [];

    return items.sort((left, right) => {
        const leftOpenRank = isTaskOpen(left) ? 0 : 1;
        const rightOpenRank = isTaskOpen(right) ? 0 : 1;
        if (leftOpenRank !== rightOpenRank) {
            return leftOpenRank - rightOpenRank;
        }

        const dueComparison = leadTaskSortKey(left).localeCompare(leadTaskSortKey(right));
        if (dueComparison !== 0) {
            return dueComparison;
        }

        const leftPriority = taskPriorityWeights[left?.priority || 'normal'] ?? 2;
        const rightPriority = taskPriorityWeights[right?.priority || 'normal'] ?? 2;
        if (leftPriority !== rightPriority) {
            return leftPriority - rightPriority;
        }

        return String(right?.created_at || '').localeCompare(String(left?.created_at || ''));
    });
});
const leadTaskFilterOptions = computed(() => {
    const tasks = Array.isArray(props.lead?.tasks) ? props.lead.tasks : [];

    return [
        {
            id: 'all',
            label: t('tasks.follow_up.all'),
            count: tasks.length,
        },
        {
            id: 'today',
            label: t('tasks.follow_up.today'),
            count: tasks.filter((task) => isTaskDueToday(task)).length,
        },
        {
            id: 'overdue',
            label: t('tasks.follow_up.overdue'),
            count: tasks.filter((task) => isTaskOverdue(task)).length,
        },
    ];
});
const filteredLeadTasks = computed(() => {
    if (prospectTaskFilter.value === 'today') {
        return leadTasks.value.filter((task) => isTaskDueToday(task));
    }

    if (prospectTaskFilter.value === 'overdue') {
        return leadTasks.value.filter((task) => isTaskOverdue(task));
    }

    return leadTasks.value;
});

const statusHistoryFromLabel = (entry) => entry?.from_status
    ? statusLabel(entry.from_status)
    : t('requests.status_history.initial_state');

const statusHistoryActorLabel = (entry) => entry?.user?.name || t('requests.status_history.system');

const prependProspectInteraction = (interaction) => {
    if (!interaction?.id) {
        return;
    }

    prospectInteractionItems.value = [
        interaction,
        ...prospectInteractionItems.value.filter((item) => item?.id !== interaction.id),
    ];
};

const handleSalesActivityLogged = (payload) => {
    prependProspectInteraction(payload?.interaction);
};

const assigneeOptions = computed(() =>
    (props.assignees || []).map((assignee) => ({
        id: String(assignee.id),
        name: assignee.name || t('requests.labels.unassigned'),
    }))
);

const taskAssigneeState = ref({});
const taskAssignedState = ref({});
const assigningTaskId = ref(null);
const assigneeNameLookup = computed(() =>
    Object.fromEntries(
        assigneeOptions.value.map((assignee) => [String(assignee.id), assignee.name || t('requests.labels.unassigned')])
    )
);

const syncTaskAssigneeState = () => {
    const nextState = {};
    (props.lead?.tasks || []).forEach((task) => {
        nextState[task.id] = task?.assigned_team_member_id
            ? String(task.assigned_team_member_id)
            : '';
    });
    taskAssigneeState.value = nextState;
    taskAssignedState.value = { ...nextState };
};

syncTaskAssigneeState();

watch(
    () => props.lead?.tasks,
    () => {
        syncTaskAssigneeState();
    },
    { deep: true }
);

const isAssigningTask = (taskId) => assigningTaskId.value === taskId;
const taskAssignedId = (task) => taskAssignedState.value[task?.id] || '';
const taskAssigneeName = (task) => {
    const assignedId = taskAssignedId(task);
    if (!assignedId) {
        return t('requests.labels.unassigned');
    }

    return assigneeNameLookup.value[assignedId]
        || task?.assignee?.user?.name
        || task?.assignee?.name
        || t('requests.labels.unassigned');
};
const taskAssigneeBadgeClass = (task) => {
    if (!taskAssignedId(task)) {
        return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }

    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
};
const taskAssigneeBadgeLabel = (task) => (taskAssignedId(task)
    ? t('requests.tasks.assigned_badge')
    : t('requests.tasks.unassigned_badge'));

const assignTaskAssignee = (task) => {
    if (isArchived.value || !task?.id || assigningTaskId.value) {
        return;
    }

    const selected = taskAssigneeState.value[task.id] || '';
    const previousAssigned = taskAssignedState.value[task.id] || '';
    if (selected === previousAssigned) {
        return;
    }

    assigningTaskId.value = task.id;

    router.patch(route('task.assign', task.id), {
        assigned_team_member_id: selected ? Number(selected) : null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            taskAssignedState.value = {
                ...taskAssignedState.value,
                [task.id]: selected,
            };
        },
        onError: () => {
            taskAssigneeState.value = {
                ...taskAssigneeState.value,
                [task.id]: previousAssigned,
            };
        },
        onFinish: () => {
            assigningTaskId.value = null;
        },
    });
};

const mediaLabel = (media) => media?.original_name || media?.path || t('requests.media.file');
const isImage = (media) => media?.mime && media.mime.startsWith('image/');

const scoreData = computed(() => buildLeadScore(props.lead, t));
const campaignOrigin = computed(() => props.campaignOrigin || null);
const hasCampaignOrigin = computed(() => Boolean(campaignOrigin.value?.campaign));
const originUtmEntries = computed(() =>
    Object.entries(campaignOrigin.value?.utm || {})
        .filter(([, value]) => Boolean(value))
);

const campaignOriginKindLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) {
        return '-';
    }

    const translated = t(`requests.origin.kinds.${normalized}`);
    return translated === `requests.origin.kinds.${normalized}` ? normalized : translated;
};

const campaignOriginDirectionLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) {
        return '-';
    }

    const translated = t(`requests.origin.directions.${normalized}`);
    return translated === `requests.origin.directions.${normalized}` ? normalized : translated;
};

const sourceOptions = computed(() => ([
    { id: 'manual', name: t('requests.sources.manual') },
    { id: 'web_form', name: t('requests.sources.web_form') },
    { id: 'phone', name: t('requests.sources.phone') },
    { id: 'email', name: t('requests.sources.email') },
    { id: 'whatsapp', name: t('requests.sources.whatsapp') },
    { id: 'sms', name: t('requests.sources.sms') },
    { id: 'qr', name: t('requests.sources.qr') },
    { id: 'portal', name: t('requests.sources.portal') },
    { id: 'api', name: t('requests.sources.api') },
    { id: 'import', name: t('requests.sources.import') },
    { id: 'referral', name: t('requests.sources.referral') },
    { id: 'ads', name: t('requests.sources.ads') },
    { id: 'other', name: t('requests.sources.other') },
]));

const urgencyOptions = computed(() => ([
    { id: 'urgent', name: t('requests.urgency.urgent') },
    { id: 'high', name: t('requests.urgency.high') },
    { id: 'medium', name: t('requests.urgency.medium') },
    { id: 'low', name: t('requests.urgency.low') },
]));

const serviceableOptions = computed(() => ([
    { id: '', name: t('requests.quality.unknown') },
    { id: '1', name: t('requests.quality.serviceable') },
    { id: '0', name: t('requests.quality.not_serviceable') },
]));

const budgetValue = computed(() => {
    const meta = props.lead?.meta || {};
    return meta.budget ?? '';
});

const qualityForm = useForm({
    channel: props.lead?.channel || 'manual',
    urgency: props.lead?.urgency || '',
    is_serviceable: props.lead?.is_serviceable === null || props.lead?.is_serviceable === undefined
        ? ''
        : (props.lead?.is_serviceable ? '1' : '0'),
    budget: budgetValue.value,
});

const submitQuality = () => {
    if (isArchived.value || qualityForm.processing) {
        return;
    }

    qualityForm.transform((data) => ({
        channel: data.channel || null,
        urgency: data.urgency || null,
        is_serviceable: data.is_serviceable === '' ? null : data.is_serviceable === '1',
        meta: {
            ...(props.lead?.meta || {}),
            budget: data.budget === '' ? null : Number(data.budget),
        },
    })).put(route('prospects.update', props.lead.id), {
        preserveScroll: true,
    });
};

const mergeDuplicate = (duplicate) => {
    if (isArchived.value || !duplicate?.id) {
        return;
    }
    if (!confirm(t('requests.duplicates.merge_confirm'))) {
        return;
    }
    router.post(route('prospects.merge', props.lead.id), {
        source_id: duplicate.id,
    }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="titleForLead" />
    <AuthenticatedLayout>
        <div class="space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ titleForLead }}
                        </h1>
                        <span class="inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium" :class="statusClass(lead.status)">
                            {{ statusLabel(lead.status) }}
                        </span>
                        <span class="inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                            {{ $t('requests.badges.score') }}: {{ scoreData.score }}
                        </span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="badge in scoreData.badges"
                            :key="badge.key + badge.label"
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="badgeClass(badge.tone)"
                        >
                            {{ badge.label }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('requests.labels.request_number', { id: lead.id }) }} · {{ formatDate(lead.created_at) }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('prospects.index')"
                        class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        {{ $t('requests.actions.back') }}
                    </Link>
                    <Link
                        :href="route('pipeline.timeline', { entityType: 'request', entityId: lead.id })"
                        class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        {{ $t('requests.actions.timeline') }}
                    </Link>
                    <Link
                        v-if="lead.quote"
                        :href="route('customer.quote.show', lead.quote.id)"
                        class="inline-flex items-center gap-2 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                    >
                        {{ $t('requests.actions.view_quote') }}
                    </Link>
                    <button
                        v-if="!isArchived"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                        @click="archiveLead"
                    >
                        {{ $t('requests.actions.archive') }}
                    </button>
                    <button
                        v-else-if="!isAnonymized"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                        @click="restoreLead"
                    >
                        {{ $t('requests.actions.restore') }}
                    </button>
                    <button
                        v-if="isArchived && !isAnonymized"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="anonymizeLead"
                    >
                        {{ $t('requests.actions.anonymize') }}
                    </button>
                </div>
            </div>

            <section
                v-if="isArchived"
                class="rounded-sm border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-900 shadow-sm dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-semibold">
                        {{ $t('requests.show.archived_banner') }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-sm bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                            {{ $t('requests.status.archived') }}
                        </span>
                        <span
                            v-if="isAnonymized"
                            class="inline-flex items-center rounded-sm bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-800 dark:bg-neutral-700 dark:text-neutral-100"
                        >
                            {{ $t('requests.status.anonymized') }}
                        </span>
                    </div>
                </div>
                <p class="mt-2">
                    {{ isAnonymized ? $t('requests.show.anonymized_read_only') : $t('requests.show.archived_read_only') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-amber-800/90 dark:text-amber-100/80">
                    <span>{{ $t('requests.show.archived_at') }}: {{ formatDate(lead.archived_at) || '-' }}</span>
                    <span>{{ $t('requests.show.archived_by') }}: {{ archivedByLabel }}</span>
                    <span v-if="!isAnonymized && lead.archive_reason">{{ $t('requests.show.archive_reason') }}: {{ lead.archive_reason }}</span>
                    <span v-if="isAnonymized">{{ $t('requests.show.anonymized_at') }}: {{ anonymizedAtLabel || '-' }}</span>
                    <span v-if="anonymizationReason">{{ $t('requests.show.anonymized_reason') }}: {{ anonymizationReason }}</span>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.current_status') }}</div>
                    <div class="mt-2">
                        <span class="inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium" :class="statusClass(lead.status)">
                            {{ statusLabel(lead.status) }}
                        </span>
                        <span
                            v-if="isArchived"
                            class="ml-2 inline-flex items-center rounded-sm bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-200"
                        >
                            {{ $t('requests.status.archived') }}
                        </span>
                        <span
                            v-if="isAnonymized"
                            class="ml-2 inline-flex items-center rounded-sm bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-800 dark:bg-neutral-700 dark:text-neutral-100"
                        >
                            {{ $t('requests.status.anonymized') }}
                        </span>
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.table.assignee') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ lead.assignee?.user?.name || lead.assignee?.name || $t('requests.labels.unassigned') }}
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.table.priority') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ priorityLabel }}
                    </div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('requests.triage.priority_short', { value: lead.triage_priority || 0 }) }}
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.table.last_activity') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ lastActivityLabel }}
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.table.follow_up') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ nextActionLabel || $t('requests.labels.no_follow_up') }}
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.table.type') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ requestTypeLabel }}
                    </div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ sourceLabel }}
                    </div>
                </section>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),320px]">
                <div class="space-y-4">
                    <ProspectInteractionTimeline :items="prospectInteractionItems" />

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('requests.show.details') }}
                            </h2>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ sourceLabel || $t('requests.show.channel_fallback') }}
                            </span>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-3 text-sm text-stone-600 dark:text-neutral-300 sm:grid-cols-2">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.company') }}</div>
                                <div class="mt-1 text-stone-800 dark:text-neutral-200">
                                    {{ companyLabel }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.request_type') }}</div>
                                <div class="mt-1 text-stone-800 dark:text-neutral-200">
                                    {{ requestTypeLabel }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.service') }}</div>
                                <div class="mt-1 text-stone-800 dark:text-neutral-200">
                                    {{ lead.service_type || $t('requests.show.service_fallback') }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.urgency') }}</div>
                                <div class="mt-1 text-stone-800 dark:text-neutral-200">
                                    {{ lead.urgency || $t('requests.show.urgency_fallback') }}
                                </div>
                            </div>
                            <div class="sm:col-span-2">
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.description') }}</div>
                                <p class="mt-1 text-stone-700 dark:text-neutral-200">
                                    {{ isAnonymized ? $t('requests.show.anonymized_description') : (lead.description || $t('requests.show.description_empty')) }}
                                </p>
                            </div>
                            <div class="sm:col-span-2">
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.address') }}</div>
                                <p class="mt-1 text-stone-700 dark:text-neutral-200">
                                    {{ addressLabel }}
                                </p>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.contact_consent') }}</div>
                                <div class="mt-1 text-stone-800 dark:text-neutral-200">
                                    {{ contactConsentLabel }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.marketing_consent') }}</div>
                                <div class="mt-1 text-stone-800 dark:text-neutral-200">
                                    {{ marketingConsentLabel }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('requests.notes.title') }}
                            </h2>
                        </div>
                        <div v-if="lead.notes?.length" class="mt-3 space-y-3">
                            <div
                                v-for="note in lead.notes"
                                :key="note.id"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ note.user?.name || $t('requests.notes.author_fallback') }} ·
                                        <span :title="formatAbsoluteDate(note.created_at)">{{ formatDate(note.created_at) }}</span>
                                    </div>
                                    <button
                                        v-if="!isArchived"
                                        type="button"
                                        class="text-xs text-rose-600 hover:text-rose-700"
                                        @click="deleteNote(note)"
                                    >
                                        {{ $t('requests.notes.delete') }}
                                    </button>
                                </div>
                                <p class="mt-2">{{ note.body }}</p>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.notes.empty') }}
                        </p>

                        <form v-if="!isArchived" class="mt-4 space-y-2" @submit.prevent="submitNote">
                            <FloatingTextarea v-model="noteForm.body" :label="$t('requests.notes.add')" />
                            <InputError class="mt-1" :message="noteForm.errors.body" />
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                                    :disabled="noteForm.processing"
                                >
                                    {{ $t('requests.notes.save') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('requests.media.title') }}
                            </h2>
                            <button
                                v-if="hasMedia && !isArchived"
                                type="button"
                                data-hs-overlay="#request-media-modal"
                                class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            >
                                {{ $t('requests.media.upload') }}
                            </button>
                        </div>

                        <div v-if="lead.media?.length" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div
                                v-for="media in lead.media"
                                :key="media.id"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ media.user?.name || $t('requests.media.author_fallback') }} ·
                                        <span :title="formatAbsoluteDate(media.created_at)">{{ formatDate(media.created_at) }}</span>
                                    </div>
                                    <button
                                        v-if="!isArchived"
                                        type="button"
                                        class="text-xs text-rose-600 hover:text-rose-700"
                                        @click="deleteMedia(media)"
                                    >
                                        {{ $t('requests.media.delete') }}
                                    </button>
                                </div>
                                <div class="mt-2 flex items-center gap-3">
                                    <img
                                        v-if="isImage(media) && media.url"
                                        :src="media.url"
                                        class="h-16 w-16 rounded-sm object-cover"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-stone-800 dark:text-neutral-200">
                                            {{ mediaLabel(media) }}
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ media.size ? formatBytes(media.size) : '-' }}
                                        </div>
                                        <div class="mt-2 flex items-center gap-2">
                                            <a
                                                v-if="media.url"
                                                :href="media.url"
                                                target="_blank"
                                                rel="noreferrer"
                                                class="text-xs font-medium text-emerald-600 hover:text-emerald-700"
                                            >
                                                {{ $t('requests.media.view') }}
                                            </a>
                                            <a
                                                v-if="media.url"
                                                :href="media.url"
                                                target="_blank"
                                                download
                                                class="text-xs text-stone-500 hover:text-stone-700"
                                            >
                                                {{ $t('requests.media.download') }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.media.empty') }}
                        </p>

                        <form v-if="!hasMedia && !isArchived" class="mt-4 space-y-2" @submit.prevent="submitMedia">
                            <input
                                ref="mediaInputRef"
                                type="file"
                                class="block w-full text-sm text-stone-700 file:mr-4 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-200 dark:file:bg-neutral-700 dark:file:text-neutral-200"
                                @change="handleMediaFile"
                            />
                            <InputError class="mt-1" :message="mediaForm.errors.file" />
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                                    :disabled="mediaForm.processing || !mediaForm.file"
                                >
                                    {{ $t('requests.media.upload') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('requests.tasks.title') }}
                            </h2>
                            <button
                                v-if="hasTasks && !isArchived"
                                type="button"
                                data-hs-overlay="#request-task-modal"
                                class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            >
                                {{ $t('requests.tasks.create') }}
                            </button>
                        </div>

                        <div v-if="lead.tasks?.length" class="mt-3 space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="option in leadTaskFilterOptions"
                                    :key="`prospect-task-filter-${option.id}`"
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold transition"
                                    :class="prospectTaskFilter === option.id
                                        ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-300'
                                        : 'border-stone-200 bg-white text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                    @click="prospectTaskFilter = option.id"
                                >
                                    <span>{{ option.label }}</span>
                                    <span class="rounded-full bg-black/5 px-2 py-0.5 text-[11px] dark:bg-white/10">
                                        {{ option.count }}
                                    </span>
                                </button>
                            </div>

                            <div class="space-y-2">
                            <div
                                v-for="task in filteredLeadTasks"
                                :key="task.id"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                <div class="flex w-full flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <Link :href="route('task.show', task.id)" class="text-sm font-semibold text-stone-800 hover:text-emerald-600 dark:text-neutral-200">
                                            {{ task.title }}
                                        </Link>
                                        <div class="mt-1 flex items-center gap-2 text-xs">
                                            <span class="rounded-full px-2 py-0.5 font-medium" :class="taskAssigneeBadgeClass(task)">
                                                {{ taskAssigneeBadgeLabel(task) }}
                                            </span>
                                            <span class="text-stone-500 dark:text-neutral-400">
                                                {{ taskAssigneeName(task) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="rounded-full px-2 py-0.5 font-medium" :class="taskPriorityClass(task.priority)">
                                            {{ taskPriorityLabel(task.priority) }}
                                        </span>
                                        <span class="rounded-full px-2 py-0.5 font-medium" :class="taskStatusClass(task.status)">
                                            {{ taskStatusLabel(task.status) }}
                                        </span>
                                        <span class="text-stone-500 dark:text-neutral-400">
                                            {{ task.due_date ? formatDate(task.due_date) : $t('requests.tasks.no_due') }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="assigneeOptions.length" class="mt-2 flex w-full flex-wrap items-center gap-2">
                                    <select
                                        v-model="taskAssigneeState[task.id]"
                                        :disabled="isArchived"
                                        class="block min-w-52 rounded-sm border-stone-300 text-xs focus:border-emerald-600 focus:ring-emerald-600 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-200"
                                    >
                                        <option value="">{{ $t('requests.labels.unassigned') }}</option>
                                        <option
                                            v-for="assignee in assigneeOptions"
                                            :key="`task-${task.id}-assignee-${assignee.id}`"
                                            :value="assignee.id"
                                        >
                                            {{ assignee.name }}
                                        </option>
                                    </select>

                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        :disabled="isArchived || isAssigningTask(task.id) || (taskAssigneeState[task.id] || '') === taskAssignedId(task)"
                                        @click="assignTaskAssignee(task)"
                                    >
                                        {{ $t('requests.tasks.assign_action') }}
                                    </button>
                                </div>
                            </div>
                            </div>
                            <p v-if="!filteredLeadTasks.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('tasks.empty.no_tasks_for_filter') }}
                            </p>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.tasks.empty') }}
                        </p>

                        <form v-if="!hasTasks && !isArchived" class="mt-4 space-y-2" @submit.prevent="submitTask">
                            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                                <FloatingInput v-model="taskForm.title" :label="$t('requests.tasks.title_label')" />
                                <DatePicker v-model="taskForm.due_date" :label="$t('requests.tasks.due_date')" />
                                <FloatingSelect
                                    v-model="taskForm.priority"
                                    :label="$t('tasks.form.priority')"
                                    :options="[
                                        { id: 'low', name: $t('tasks.priority.low') },
                                        { id: 'normal', name: $t('tasks.priority.normal') },
                                        { id: 'high', name: $t('tasks.priority.high') },
                                        { id: 'urgent', name: $t('tasks.priority.urgent') },
                                    ]"
                                />
                            </div>
                            <FloatingSelect
                                v-model="taskForm.assigned_team_member_id"
                                :label="$t('requests.tasks.assignee')"
                                :options="assigneeOptions"
                                :placeholder="$t('requests.labels.unassigned')"
                            />
                            <FloatingTextarea v-model="taskForm.description" :label="$t('requests.tasks.description')" />
                            <InputError class="mt-1" :message="taskForm.errors.title" />
                            <InputError class="mt-1" :message="taskForm.errors.priority" />
                            <InputError class="mt-1" :message="taskForm.errors.assigned_team_member_id" />
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                                    :disabled="taskForm.processing"
                                >
                                    {{ $t('requests.tasks.create') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <SalesActivityPanel
                        :items="activity"
                        :can-log="canLogSalesActivity && !isArchived"
                        :quick-actions="salesActivityQuickActions"
                        :manual-actions="salesActivityManualActions"
                        :store-route="route('crm.sales-activities.requests.store', lead.id)"
                        i18n-prefix="requests.sales_activity"
                        dialog-id="request-sales-activity-modal"
                        @logged="handleSalesActivityLogged"
                    />
                </div>

                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('requests.show.summary') }}
                        </h2>
                        <div class="mt-3 space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.table.customer') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ displayCustomer }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.table.assignee') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ lead.assignee?.user?.name || lead.assignee?.name || $t('requests.labels.unassigned') }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.table.follow_up') }}</span>
                                <span
                                    v-if="lead.next_follow_up_at"
                                    :class="isOverdue(lead) ? 'text-rose-600 dark:text-rose-400' : 'text-stone-800 dark:text-neutral-200'"
                                    :title="formatAbsoluteDate(lead.next_follow_up_at)"
                                >
                                    {{ formatDate(lead.next_follow_up_at) }}
                                </span>
                                <span v-else class="text-stone-800 dark:text-neutral-200">
                                    {{ $t('requests.labels.no_follow_up') }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.show.status_updated') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ formatDate(lead.status_updated_at) || '-' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.show.source') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ sourceLabel || '-' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.show.request_type') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ requestTypeLabel }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.table.priority') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ priorityLabel }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.table.last_activity') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ lastActivityLabel }}
                                </span>
                            </div>
                            <div v-if="isArchived" class="flex items-center justify-between gap-3">
                                <span>{{ $t('requests.show.archived_at') }}</span>
                                <span class="text-right text-stone-800 dark:text-neutral-200">
                                    {{ formatDate(lead.archived_at) || '-' }}
                                </span>
                            </div>
                            <div v-if="isArchived" class="flex items-center justify-between gap-3">
                                <span>{{ $t('requests.show.archived_by') }}</span>
                                <span class="text-right text-stone-800 dark:text-neutral-200">
                                    {{ archivedByLabel }}
                                </span>
                            </div>
                            <div v-if="isArchived && !isAnonymized && lead.archive_reason" class="flex items-center justify-between gap-3">
                                <span>{{ $t('requests.show.archive_reason') }}</span>
                                <span class="text-right text-stone-800 dark:text-neutral-200">
                                    {{ lead.archive_reason }}
                                </span>
                            </div>
                            <div v-if="isAnonymized" class="flex items-center justify-between gap-3">
                                <span>{{ $t('requests.show.anonymized_at') }}</span>
                                <span class="text-right text-stone-800 dark:text-neutral-200">
                                    {{ anonymizedAtLabel || '-' }}
                                </span>
                            </div>
                            <div v-if="anonymizationReason" class="flex items-center justify-between gap-3">
                                <span>{{ $t('requests.show.anonymized_reason') }}</span>
                                <span class="text-right text-stone-800 dark:text-neutral-200">
                                    {{ anonymizationReason }}
                                </span>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('requests.status_history.title') }}
                        </h2>
                        <div v-if="statusHistoryItems.length" class="mt-3 space-y-3">
                            <div
                                v-for="entry in statusHistoryItems"
                                :key="entry.id"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                            <span>{{ statusHistoryFromLabel(entry) }}</span>
                                            <span class="mx-2 text-stone-400">-></span>
                                            <span>{{ statusLabel(entry.to_status) }}</span>
                                        </div>
                                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ statusHistoryActorLabel(entry) }} ·
                                            <span :title="formatAbsoluteDate(entry.created_at)">{{ formatDate(entry.created_at) }}</span>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusClass(entry.to_status)">
                                        {{ statusLabel(entry.to_status) }}
                                    </span>
                                </div>
                                <p v-if="entry.comment" class="mt-2 text-sm text-stone-600 dark:text-neutral-300">
                                    {{ entry.comment }}
                                </p>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.status_history.empty') }}
                        </p>
                    </section>

                    <section v-if="hasCampaignOrigin" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('requests.origin.title') }}
                        </h2>
                        <div class="mt-3 space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                            <div class="flex items-center justify-between gap-3">
                                <span>{{ $t('requests.origin.campaign') }}</span>
                                <Link
                                    :href="route('campaigns.show', campaignOrigin.campaign.id)"
                                    class="text-right font-medium text-emerald-700 hover:text-emerald-800 dark:text-emerald-300"
                                >
                                    {{ campaignOrigin.campaign.name }}
                                </Link>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.origin.kind') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ campaignOriginKindLabel(campaignOrigin.kind) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.origin.direction') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ campaignOriginDirectionLabel(campaignOrigin.direction) }}
                                </span>
                            </div>
                            <div v-if="campaignOrigin.channel" class="flex items-center justify-between">
                                <span>{{ $t('requests.origin.channel') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    {{ campaignOrigin.channel }}
                                </span>
                            </div>
                            <div v-if="campaignOrigin.prospect && !isAnonymized" class="flex items-center justify-between">
                                <span>{{ $t('requests.origin.prospect') }}</span>
                                <span class="text-right text-stone-800 dark:text-neutral-200">
                                    #{{ campaignOrigin.prospect.id }} · {{ campaignOrigin.prospect.company_name || campaignOrigin.prospect.contact_name || '-' }}
                                </span>
                            </div>
                            <div v-if="campaignOrigin.batch" class="flex items-center justify-between">
                                <span>{{ $t('requests.origin.batch') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">
                                    #{{ campaignOrigin.batch.batch_number }}
                                </span>
                            </div>
                            <div v-if="campaignOrigin.first_outreach_at" class="flex items-center justify-between">
                                <span>{{ $t('requests.origin.first_outreach') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200" :title="formatAbsoluteDate(campaignOrigin.first_outreach_at)">
                                    {{ formatDate(campaignOrigin.first_outreach_at) }}
                                </span>
                            </div>
                            <div v-if="campaignOrigin.converted_at" class="flex items-center justify-between">
                                <span>{{ $t('requests.origin.attributed_at') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200" :title="formatAbsoluteDate(campaignOrigin.converted_at)">
                                    {{ formatDate(campaignOrigin.converted_at) }}
                                </span>
                            </div>
                        </div>

                        <div v-if="originUtmEntries.length" class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                            <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ $t('requests.origin.utm') }}</div>
                            <div class="mt-2 space-y-1">
                                <div v-for="[key, value] in originUtmEntries" :key="`origin-utm-${key}`" class="flex items-center justify-between gap-3">
                                    <span class="uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ key }}</span>
                                    <span class="text-right text-stone-800 dark:text-neutral-200">{{ value }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('requests.quality.title') }}
                        </h2>
                        <form class="mt-3 space-y-3" @submit.prevent="submitQuality">
                            <FloatingSelect
                                v-model="qualityForm.channel"
                                :disabled="isArchived"
                                :label="$t('requests.quality.source')"
                                :options="sourceOptions"
                                :placeholder="$t('requests.quality.source_placeholder')"
                            />
                            <FloatingSelect
                                v-model="qualityForm.urgency"
                                :disabled="isArchived"
                                :label="$t('requests.quality.urgency')"
                                :options="urgencyOptions"
                                :placeholder="$t('requests.quality.urgency_placeholder')"
                            />
                            <FloatingSelect
                                v-model="qualityForm.is_serviceable"
                                :disabled="isArchived"
                                :label="$t('requests.quality.serviceable_label')"
                                :options="serviceableOptions"
                                :placeholder="$t('requests.quality.serviceable_placeholder')"
                            />
                            <FloatingInput
                                v-model="qualityForm.budget"
                                :disabled="isArchived"
                                type="number"
                                step="0.01"
                                :label="$t('requests.quality.budget')"
                            />
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                                    :disabled="isArchived || qualityForm.processing"
                                >
                                    {{ $t('requests.quality.save') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('requests.show.contact') }}
                        </h2>
                        <div class="mt-3 space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                            <p v-if="isAnonymized" class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                {{ $t('requests.labels.anonymized_contact') }}
                            </p>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.show.phone') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">{{ contactPhone || '-' }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('requests.show.email') }}</span>
                                <span class="text-stone-800 dark:text-neutral-200">{{ contactEmail || '-' }}</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a
                                    v-if="contactPhone"
                                    :href="`tel:${contactPhone}`"
                                    class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                >
                                    {{ $t('requests.show.call') }}
                                </a>
                                <a
                                    v-if="contactEmail"
                                    :href="`mailto:${contactEmail}`"
                                    class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                >
                                    {{ $t('requests.show.email_action') }}
                                </a>
                                <a
                                    v-if="whatsAppLink"
                                    :href="whatsAppLink"
                                    target="_blank"
                                    rel="noreferrer"
                                    class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                >
                                    {{ $t('requests.show.whatsapp') }}
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-if="duplicates?.length" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('requests.duplicates.title') }}
                        </h2>
                        <div class="mt-3 space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                            <div
                                v-for="duplicate in duplicates"
                                :key="duplicate.id"
                                class="flex flex-wrap items-start justify-between gap-3 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Link :href="route('prospects.show', duplicate.id)" class="text-sm font-semibold text-stone-800 hover:text-emerald-600 dark:text-neutral-200">
                                            {{ duplicate.title || duplicate.service_type || $t('requests.labels.request_number', { id: duplicate.id }) }}
                                        </Link>
                                        <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                            {{ $t('requests.duplicates.score', { score: duplicate.duplicate_score || 0 }) }}
                                        </span>
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ statusLabel(duplicate.status) }} · {{ formatDate(duplicate.created_at) }}
                                    </div>
                                    <div v-if="duplicate.duplicate_summary" class="mt-2 text-xs text-stone-700 dark:text-neutral-200">
                                        {{ duplicate.duplicate_summary }}
                                    </div>
                                    <div v-if="Array.isArray(duplicate.duplicate_reasons) && duplicate.duplicate_reasons.length" class="mt-2 flex flex-wrap gap-1">
                                        <span
                                            v-for="reason in duplicate.duplicate_reasons"
                                            :key="`${duplicate.id}-${reason.code}`"
                                            class="inline-flex items-center rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-stone-700 dark:bg-neutral-900 dark:text-neutral-200"
                                        >
                                            {{ reason.label }}
                                        </span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-60 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                    :disabled="isArchived"
                                    @click="mergeDuplicate(duplicate)"
                                >
                                    {{ $t('requests.duplicates.merge') }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <section v-if="lead.customer" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('requests.show.customer') }}
                        </h2>
                        <div class="mt-3 space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                            <Link
                                :href="route('customer.show', lead.customer.id)"
                                class="text-sm font-semibold text-stone-800 hover:text-emerald-600 dark:text-neutral-200"
                            >
                                {{ displayCustomer }}
                            </Link>
                            <div>{{ lead.customer.email || '-' }}</div>
                            <div>{{ lead.customer.phone || '-' }}</div>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <Modal v-if="hasMedia && !isArchived" :title="$t('requests.media.upload')" :id="'request-media-modal'">
            <form class="space-y-2" @submit.prevent="submitMedia">
                <input
                    ref="mediaInputRef"
                    type="file"
                    class="block w-full text-sm text-stone-700 file:mr-4 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-200 dark:file:bg-neutral-700 dark:file:text-neutral-200"
                    @change="handleMediaFile"
                />
                <InputError class="mt-1" :message="mediaForm.errors.file" />
                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                        :disabled="mediaForm.processing || !mediaForm.file"
                    >
                        {{ $t('requests.media.upload') }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal v-if="hasTasks && !isArchived" :title="$t('requests.tasks.create')" :id="'request-task-modal'">
            <form class="space-y-2" @submit.prevent="submitTask">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                    <FloatingInput v-model="taskForm.title" :label="$t('requests.tasks.title_label')" />
                    <DatePicker v-model="taskForm.due_date" :label="$t('requests.tasks.due_date')" />
                    <FloatingSelect
                        v-model="taskForm.priority"
                        :label="$t('tasks.form.priority')"
                        :options="[
                            { id: 'low', name: $t('tasks.priority.low') },
                            { id: 'normal', name: $t('tasks.priority.normal') },
                            { id: 'high', name: $t('tasks.priority.high') },
                            { id: 'urgent', name: $t('tasks.priority.urgent') },
                        ]"
                    />
                </div>
                <FloatingSelect
                    v-model="taskForm.assigned_team_member_id"
                    :label="$t('requests.tasks.assignee')"
                    :options="assigneeOptions"
                    :placeholder="$t('requests.labels.unassigned')"
                />
                <FloatingTextarea v-model="taskForm.description" :label="$t('requests.tasks.description')" />
                <InputError class="mt-1" :message="taskForm.errors.title" />
                <InputError class="mt-1" :message="taskForm.errors.priority" />
                <InputError class="mt-1" :message="taskForm.errors.assigned_team_member_id" />
                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                        :disabled="taskForm.processing"
                    >
                        {{ $t('requests.tasks.create') }}
                    </button>
                </div>
            </form>
        </Modal>

    </AuthenticatedLayout>
</template>
