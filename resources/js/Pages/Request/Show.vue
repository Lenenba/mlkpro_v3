<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/UI/Modal.vue';
import { humanizeDate } from '@/utils/date';
import { formatBytes } from '@/utils/media';
import { buildLeadScore, badgeClass } from '@/utils/leadScore';
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

const titleForLead = computed(() =>
    props.lead?.title || props.lead?.service_type || t('requests.labels.request_number', { id: props.lead?.id })
);

const displayCustomer = computed(() =>
    props.lead?.customer?.company_name ||
    `${props.lead?.customer?.first_name || ''} ${props.lead?.customer?.last_name || ''}`.trim() ||
    props.lead?.contact_name ||
    t('requests.labels.unknown_customer')
);

const hasMedia = computed(() => Array.isArray(props.lead?.media) && props.lead.media.length > 0);
const hasTasks = computed(() => Array.isArray(props.lead?.tasks) && props.lead.tasks.length > 0);

const closeOverlay = (selector) => {
    if (typeof window === 'undefined' || !window.HSOverlay) {
        return;
    }
    window.HSOverlay.close(selector);
};

const addressLabel = computed(() => {
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

const contactPhone = computed(() => props.lead?.contact_phone || props.lead?.customer?.phone || '');
const contactEmail = computed(() => props.lead?.contact_email || props.lead?.customer?.email || '');

const normalizePhone = (value) => String(value || '').replace(/\D/g, '');
const whatsAppLink = computed(() => {
    const digits = normalizePhone(contactPhone.value);
    return digits ? `https://wa.me/${digits}` : null;
});

const noteForm = useForm({
    body: '',
});

const submitNote = () => {
    if (noteForm.processing) {
        return;
    }
    noteForm.post(route('request.notes.store', props.lead.id), {
        preserveScroll: true,
        onSuccess: () => {
            noteForm.reset();
        },
    });
};

const deleteNote = (note) => {
    if (!note?.id) {
        return;
    }
    if (!confirm(t('requests.notes.delete_confirm'))) {
        return;
    }
    router.delete(route('request.notes.destroy', { lead: props.lead.id, note: note.id }), {
        preserveScroll: true,
    });
};

const mediaForm = useForm({
    file: null,
});
const mediaInputRef = ref(null);

const handleMediaFile = (event) => {
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
    if (mediaForm.processing || !mediaForm.file) {
        return;
    }
    mediaForm.post(route('request.media.store', props.lead.id), {
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
    if (!media?.id) {
        return;
    }
    if (!confirm(t('requests.media.delete_confirm'))) {
        return;
    }
    router.delete(route('request.media.destroy', { lead: props.lead.id, media: media.id }), {
        preserveScroll: true,
    });
};

const taskForm = useForm({
    title: '',
    description: '',
    due_date: '',
    assigned_team_member_id: props.lead?.assigned_team_member_id ? String(props.lead.assigned_team_member_id) : '',
    status: 'todo',
    standalone: true,
    request_id: props.lead?.id,
});

const submitTask = () => {
    if (taskForm.processing) {
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
            taskForm.reset('title', 'description', 'due_date');
            closeOverlay('#request-task-modal');
        },
    });
};

const taskStatusLabel = (status) => {
    switch (status) {
        case 'todo':
            return t('requests.tasks.todo');
        case 'in_progress':
            return t('requests.tasks.in_progress');
        case 'done':
            return t('requests.tasks.done');
        default:
            return status || '-';
    }
};

const taskStatusClass = (status) => {
    switch (status) {
        case 'todo':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        case 'in_progress':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
        case 'done':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const assigneeOptions = computed(() =>
    (props.assignees || []).map((assignee) => ({
        id: String(assignee.id),
        name: assignee.name || t('requests.labels.unassigned'),
    }))
);

const mediaLabel = (media) => media?.original_name || media?.path || t('requests.media.file');
const isImage = (media) => media?.mime && media.mime.startsWith('image/');

const scoreData = computed(() => buildLeadScore(props.lead, t));

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
    if (qualityForm.processing) {
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
    })).put(route('request.update', props.lead.id), {
        preserveScroll: true,
    });
};

const mergeDuplicate = (duplicate) => {
    if (!duplicate?.id) {
        return;
    }
    if (!confirm(t('requests.duplicates.merge_confirm'))) {
        return;
    }
    router.post(route('request.merge', props.lead.id), {
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
                        :href="route('request.index')"
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
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),320px]">
                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('requests.show.details') }}
                            </h2>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ lead.channel || $t('requests.show.channel_fallback') }}
                            </span>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-3 text-sm text-stone-600 dark:text-neutral-300 sm:grid-cols-2">
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
                                    {{ lead.description || $t('requests.show.description_empty') }}
                                </p>
                            </div>
                            <div class="sm:col-span-2">
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.show.address') }}</div>
                                <p class="mt-1 text-stone-700 dark:text-neutral-200">
                                    {{ addressLabel }}
                                </p>
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
                                    <button type="button" class="text-xs text-rose-600 hover:text-rose-700" @click="deleteNote(note)">
                                        {{ $t('requests.notes.delete') }}
                                    </button>
                                </div>
                                <p class="mt-2">{{ note.body }}</p>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.notes.empty') }}
                        </p>

                        <form class="mt-4 space-y-2" @submit.prevent="submitNote">
                            <FloatingTextarea v-model="noteForm.body" :label="$t('requests.notes.add')" />
                            <InputError class="mt-1" :message="noteForm.errors.body" />
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
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
                                v-if="hasMedia"
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
                                    <button type="button" class="text-xs text-rose-600 hover:text-rose-700" @click="deleteMedia(media)">
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

                        <form v-if="!hasMedia" class="mt-4 space-y-2" @submit.prevent="submitMedia">
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
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
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
                                v-if="hasTasks"
                                type="button"
                                data-hs-overlay="#request-task-modal"
                                class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            >
                                {{ $t('requests.tasks.create') }}
                            </button>
                        </div>

                        <div v-if="lead.tasks?.length" class="mt-3 space-y-2">
                            <div
                                v-for="task in lead.tasks"
                                :key="task.id"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                <div>
                                    <Link :href="route('task.show', task.id)" class="text-sm font-semibold text-stone-800 hover:text-emerald-600 dark:text-neutral-200">
                                        {{ task.title }}
                                    </Link>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ task.assignee?.user?.name || $t('requests.labels.unassigned') }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="rounded-full px-2 py-0.5 font-medium" :class="taskStatusClass(task.status)">
                                        {{ taskStatusLabel(task.status) }}
                                    </span>
                                    <span class="text-stone-500 dark:text-neutral-400">
                                        {{ task.due_date ? formatDate(task.due_date) : $t('requests.tasks.no_due') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.tasks.empty') }}
                        </p>

                        <form v-if="!hasTasks" class="mt-4 space-y-2" @submit.prevent="submitTask">
                            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                                <FloatingInput v-model="taskForm.title" :label="$t('requests.tasks.title_label')" />
                                <DatePicker v-model="taskForm.due_date" :label="$t('requests.tasks.due_date')" />
                            </div>
                            <FloatingSelect
                                v-model="taskForm.assigned_team_member_id"
                                :label="$t('requests.tasks.assignee')"
                                :options="assigneeOptions"
                                :placeholder="$t('requests.labels.unassigned')"
                            />
                            <FloatingTextarea v-model="taskForm.description" :label="$t('requests.tasks.description')" />
                            <InputError class="mt-1" :message="taskForm.errors.title" />
                            <InputError class="mt-1" :message="taskForm.errors.assigned_team_member_id" />
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                                    :disabled="taskForm.processing"
                                >
                                    {{ $t('requests.tasks.create') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('requests.activity.title') }}
                            </h2>
                        </div>
                        <div v-if="activity?.length" class="mt-3 space-y-3">
                            <div
                                v-for="item in activity"
                                :key="item.id"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ item.user?.name || $t('requests.activity.author_fallback') }}</span>
                                    <span :title="formatAbsoluteDate(item.created_at)">{{ formatDate(item.created_at) }}</span>
                                </div>
                                <div class="mt-2 font-medium text-stone-800 dark:text-neutral-200">
                                    {{ item.description || item.action }}
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.activity.empty') }}
                        </p>
                    </section>
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
                                    {{ lead.channel || '-' }}
                                </span>
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
                                :label="$t('requests.quality.source')"
                                :options="sourceOptions"
                                :placeholder="$t('requests.quality.source_placeholder')"
                            />
                            <FloatingSelect
                                v-model="qualityForm.urgency"
                                :label="$t('requests.quality.urgency')"
                                :options="urgencyOptions"
                                :placeholder="$t('requests.quality.urgency_placeholder')"
                            />
                            <FloatingSelect
                                v-model="qualityForm.is_serviceable"
                                :label="$t('requests.quality.serviceable_label')"
                                :options="serviceableOptions"
                                :placeholder="$t('requests.quality.serviceable_placeholder')"
                            />
                            <FloatingInput
                                v-model="qualityForm.budget"
                                type="number"
                                step="0.01"
                                :label="$t('requests.quality.budget')"
                            />
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700"
                                    :disabled="qualityForm.processing"
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
                                class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <div>
                                    <Link :href="route('request.show', duplicate.id)" class="text-sm font-semibold text-stone-800 hover:text-emerald-600 dark:text-neutral-200">
                                        {{ duplicate.title || duplicate.service_type || $t('requests.labels.request_number', { id: duplicate.id }) }}
                                    </Link>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ statusLabel(duplicate.status) }} · {{ formatDate(duplicate.created_at) }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
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

        <Modal v-if="hasMedia" :title="$t('requests.media.upload')" :id="'request-media-modal'">
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
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                        :disabled="mediaForm.processing || !mediaForm.file"
                    >
                        {{ $t('requests.media.upload') }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal v-if="hasTasks" :title="$t('requests.tasks.create')" :id="'request-task-modal'">
            <form class="space-y-2" @submit.prevent="submitTask">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <FloatingInput v-model="taskForm.title" :label="$t('requests.tasks.title_label')" />
                    <DatePicker v-model="taskForm.due_date" :label="$t('requests.tasks.due_date')" />
                </div>
                <FloatingSelect
                    v-model="taskForm.assigned_team_member_id"
                    :label="$t('requests.tasks.assignee')"
                    :options="assigneeOptions"
                    :placeholder="$t('requests.labels.unassigned')"
                />
                <FloatingTextarea v-model="taskForm.description" :label="$t('requests.tasks.description')" />
                <InputError class="mt-1" :message="taskForm.errors.title" />
                <InputError class="mt-1" :message="taskForm.errors.assigned_team_member_id" />
                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                        :disabled="taskForm.processing"
                    >
                        {{ $t('requests.tasks.create') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
