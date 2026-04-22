<script setup>
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';
import { crmButtonClass } from '@/utils/crmButtonStyles';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    canLog: {
        type: Boolean,
        default: false,
    },
    quickActions: {
        type: Array,
        default: () => [],
    },
    manualActions: {
        type: Array,
        default: () => [],
    },
    storeRoute: {
        type: String,
        required: true,
    },
    i18nPrefix: {
        type: String,
        default: 'requests.sales_activity',
    },
    dialogId: {
        type: String,
        default: 'sales-activity-modal',
    },
    showSubject: {
        type: Boolean,
        default: false,
    },
    resolveHref: {
        type: Function,
        default: null,
    },
    embedded: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['logged']);
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

const translate = (key, fallback = '', params = {}) => {
    const translationKey = `${props.i18nPrefix}.${key}`;
    const translated = t(translationKey, params);

    if (translated === translationKey) {
        return fallback || key;
    }

    return translated;
};

const activityItems = ref(Array.isArray(props.items) ? [...props.items] : []);
const quickActionSubmitting = ref('');
const manualSubmitting = ref(false);
const manualErrors = ref({});
const dialogOpen = ref(false);
const feedback = ref({
    tone: null,
    message: '',
});

const defaultAction = computed(() => props.manualActions?.[0]?.action || 'sales_note_added');
const manualForm = ref({
    action: defaultAction.value,
    description: '',
    note: '',
    occurred_at: '',
    due_at: '',
});

watch(
    () => props.items,
    (items) => {
        activityItems.value = Array.isArray(items) ? [...items] : [];
    },
    { deep: true }
);

watch(
    () => props.manualActions,
    () => {
        if (!manualForm.value.action) {
            manualForm.value.action = defaultAction.value;
        }
    },
    { immediate: true }
);

const openDialog = () => {
    dialogOpen.value = true;
};

const closeOverlay = () => {
    dialogOpen.value = false;
};

const resetManualForm = () => {
    manualForm.value = {
        action: defaultAction.value,
        description: '',
        note: '',
        occurred_at: '',
        due_at: '',
    };
    manualErrors.value = {};
};

const actionKey = (action) => action?.activity_key || action?.event_key || action?.action || '';
const localizeActionLabel = (action, fallback = '') => {
    const key = actionKey(action);

    if (key) {
        const translated = translate(`labels.${key}`, '');
        if (translated && translated !== `labels.${key}`) {
            return translated;
        }
    }

    return fallback || action?.label || action?.action || '';
};

const localizeQuickActionLabel = (quickAction) => {
    const key = quickAction?.id || '';

    if (key) {
        const translated = translate(`quick_actions.${key}`, '');
        if (translated && translated !== `quick_actions.${key}`) {
            return translated;
        }
    }

    return quickAction?.label || key;
};

const manualActionOptions = computed(() =>
    (props.manualActions || []).map((action) => ({
        id: action.action,
        name: localizeActionLabel(action, action.label),
    }))
);

const selectedManualAction = computed(() =>
    (props.manualActions || []).find((action) => action.action === manualForm.value.action) || null
);

const needsDueAt = computed(() => {
    const action = selectedManualAction.value;

    return Boolean(
        action?.type === 'meeting'
        || action?.type === 'next_action'
        || action?.opens_next_action
    );
});

const activeNextAction = computed(() => {
    const latestRelevant = [...activityItems.value]
        .filter((item) => item?.sales_activity && (item.sales_activity.opens_next_action || item.sales_activity.closes_next_action))
        .sort((left, right) => new Date(right.created_at || 0).getTime() - new Date(left.created_at || 0).getTime())[0];

    if (!latestRelevant?.sales_activity?.opens_next_action || latestRelevant?.sales_activity?.closes_next_action) {
        return null;
    }

    if (!latestRelevant.sales_activity?.due_at) {
        return null;
    }

    return latestRelevant;
});

const feedbackClass = computed(() => {
    if (feedback.value.tone === 'error') {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
    }

    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
});
const panelClass = computed(() => (
    props.embedded
        ? ''
        : 'rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900'
));

const requestMessage = (error) =>
    error?.response?.data?.message
    || translate('messages.error', 'Unable to log sales activity.');

const requestErrors = (error) => {
    if (error?.response?.data?.errors && typeof error.response.data.errors === 'object') {
        return error.response.data.errors;
    }

    return {};
};

const prependActivity = (activity) => {
    activityItems.value = [
        activity,
        ...activityItems.value.filter((item) => item?.id !== activity?.id),
    ];
};

const postActivity = async (payload, options = {}) => {
    const { captureFieldErrors = false } = options;

    if (captureFieldErrors) {
        manualErrors.value = {};
    }

    feedback.value = {
        tone: null,
        message: '',
    };

    try {
        const response = await axios.post(props.storeRoute, payload, {
            headers: {
                Accept: 'application/json',
            },
        });

        prependActivity(response.data.activity);
        emit('logged', response.data.activity);

        feedback.value = {
            tone: 'success',
            message: response.data.message || translate('messages.logged', 'Sales activity logged.'),
        };

        return response.data.activity;
    } catch (error) {
        if (captureFieldErrors) {
            manualErrors.value = requestErrors(error);
        }

        feedback.value = {
            tone: 'error',
            message: requestMessage(error),
        };

        return null;
    }
};

const submitQuickAction = async (quickAction) => {
    if (!quickAction?.id || quickActionSubmitting.value) {
        return;
    }

    quickActionSubmitting.value = quickAction.id;
    await postActivity({
        quick_action: quickAction.id,
    });
    quickActionSubmitting.value = '';
};

const submitManualAction = async () => {
    if (manualSubmitting.value) {
        return;
    }

    manualSubmitting.value = true;

    const activity = await postActivity({
        action: manualForm.value.action || null,
        description: manualForm.value.description || null,
        note: manualForm.value.note || null,
        occurred_at: manualForm.value.occurred_at || null,
        due_at: manualForm.value.due_at || null,
    }, {
        captureFieldErrors: true,
    });

    if (activity) {
        resetManualForm();
        closeOverlay();
    }

    manualSubmitting.value = false;
};

const neutralPillClass = 'border border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300';

const humanizeToken = (value) => {
    const normalized = String(value || '').replace(/_/g, ' ').trim();

    if (!normalized) {
        return '';
    }

    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
};

const primaryActivityPayload = (item) =>
    item?.sales_activity
    || item?.message_event
    || item?.meeting_event
    || null;

const messageDirectionLabel = (direction) => {
    if (!direction) {
        return null;
    }

    const translated = translate(`message_directions.${direction}`, '');

    return translated && translated !== `message_directions.${direction}`
        ? translated
        : humanizeToken(direction);
};

const meetingStateLabel = (state) => {
    if (!state) {
        return null;
    }

    const translated = translate(`meeting_states.${state}`, '');

    return translated && translated !== `meeting_states.${state}`
        ? translated
        : humanizeToken(state);
};

const activityBadgeClass = (item) => {
    if (item?.sales_activity) {
        switch (item.sales_activity.type) {
            case 'next_action':
                return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
            case 'meeting':
                return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
            case 'call':
                return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
            case 'call_outcome':
                return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
            case 'note':
                return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
            default:
                return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
        }
    }

    if (item?.message_event) {
        switch (item.message_event.delivery_state) {
            case 'sent':
                return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
            case 'received':
                return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
            case 'failed':
                return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
            case 'retry_scheduled':
                return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
            default:
                return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
        }
    }

    if (item?.meeting_event) {
        switch (item.meeting_event.lifecycle_state) {
            case 'completed':
                return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
            case 'scheduled':
                return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
            default:
                return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
        }
    }

    return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
};

const dueBadgeClass = (item) => {
    const dueAt = item?.sales_activity?.due_at;

    if (!dueAt) {
        return '';
    }

    return new Date(dueAt).getTime() < Date.now()
        ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'
        : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
};

const eventTimeBadgeClass = (tone = 'default') => {
    switch (tone) {
        case 'meeting':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
        case 'completed':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'warning':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const activityTypeLabel = (type) => {
    if (!type) {
        return translate('types.system', 'System');
    }

    const translated = translate(`types.${type}`, '');
    return translated && translated !== `types.${type}` ? translated : type;
};

const activitySecondaryChips = (item) => {
    if (item?.sales_activity) {
        const chips = [];

        if (item.sales_activity.type) {
            chips.push({
                key: 'sales-type',
                label: activityTypeLabel(item.sales_activity.type),
                className: neutralPillClass,
                title: '',
            });
        }

        if (item.sales_activity.due_at) {
            chips.push({
                key: 'sales-due',
                label: `${translate('due_prefix', 'Due')} ${formatDate(item.sales_activity.due_at)}`,
                className: dueBadgeClass(item),
                title: formatAbsoluteDate(item.sales_activity.due_at),
            });
        }

        return chips;
    }

    if (item?.message_event) {
        const chips = [];
        const directionLabel = messageDirectionLabel(item.message_event.direction);

        if (directionLabel) {
            chips.push({
                key: 'message-direction',
                label: directionLabel,
                className: neutralPillClass,
                title: '',
            });
        }

        if (item.message_event.delivery_state === 'retry_scheduled' && item.message_event.scheduled_for) {
            chips.push({
                key: 'message-scheduled-for',
                label: `${translate('meta.scheduled_for_prefix', 'Scheduled')} ${formatDate(item.message_event.scheduled_for)}`,
                className: eventTimeBadgeClass('warning'),
                title: formatAbsoluteDate(item.message_event.scheduled_for),
            });
        }

        return chips;
    }

    if (item?.meeting_event) {
        const chips = [];

        if (item.meeting_event.provider) {
            chips.push({
                key: 'meeting-provider',
                label: humanizeToken(item.meeting_event.provider),
                className: neutralPillClass,
                title: '',
            });
        }

        if (item.meeting_event.completed_at) {
            chips.push({
                key: 'meeting-completed-at',
                label: `${translate('meta.completed_prefix', 'Completed')} ${formatDate(item.meeting_event.completed_at)}`,
                className: eventTimeBadgeClass('completed'),
                title: formatAbsoluteDate(item.meeting_event.completed_at),
            });
        } else if (item.meeting_event.start_at) {
            chips.push({
                key: 'meeting-start-at',
                label: `${translate('meta.start_prefix', 'Starts')} ${formatDate(item.meeting_event.start_at)}`,
                className: eventTimeBadgeClass('meeting'),
                title: formatAbsoluteDate(item.meeting_event.start_at),
            });
        }

        return chips;
    }

    return [];
};

const activityLabel = (item) => {
    const payload = primaryActivityPayload(item);

    if (payload) {
        return localizeActionLabel(payload, payload.label);
    }

    return translate('types.system', 'System');
};

const activityHeadline = (item) => {
    const fallbackLabel = activityLabel(item);
    const description = String(item?.description || '').trim();
    const payloadLabel = String(primaryActivityPayload(item)?.label || '').trim();

    if (!description) {
        return fallbackLabel || item?.action || translate('messages.logged', 'Sales activity logged.');
    }

    if (payloadLabel && description === payloadLabel) {
        return fallbackLabel;
    }

    return description;
};

const activityBody = (item) => {
    const note = String(item?.properties?.note || '').trim();
    return note || null;
};

const activityMeta = (item) => {
    if (item?.message_event) {
        const parts = [];
        const prefix = item.message_event.direction === 'inbound'
            ? translate('meta.from_prefix', 'From')
            : translate('meta.to_prefix', 'To');

        if (item.message_event.email) {
            parts.push(`${prefix} ${item.message_event.email}`);
        }

        if (item.message_event.retry_attempt) {
            parts.push(`${translate('meta.retry_attempt_prefix', 'Retry')} #${item.message_event.retry_attempt}`);
        }

        if (item.message_event.assistant) {
            parts.push(translate('meta.assistant_label', 'Assistant'));
        }

        return parts.join(' • ') || null;
    }

    if (item?.meeting_event) {
        const parts = [];
        const stateLabel = meetingStateLabel(item.meeting_event.lifecycle_state);

        if (stateLabel) {
            parts.push(stateLabel);
        }

        if (item.meeting_event.location) {
            parts.push(item.meeting_event.location);
        }

        return parts.join(' • ') || null;
    }

    return null;
};

const actorLabel = (item) => item?.user?.name || translate('actor_fallback', 'System');
const itemHref = (item) => (typeof props.resolveHref === 'function' ? props.resolveHref(item) : null);
</script>

<template>
    <section :class="panelClass">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ translate('title', 'Sales activity') }}
                </h2>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ translate('subtitle', 'Keep call outcomes, notes, next actions, and meetings in one place.') }}
                </p>
            </div>
            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                {{ activityItems.length }}
            </span>
        </div>

        <div v-if="feedback.message" class="mt-3 rounded-sm border px-3 py-2 text-sm" :class="feedbackClass">
            {{ feedback.message }}
        </div>

        <div v-if="canLog" class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/70">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ translate('quick_actions_title', 'Quick actions') }}
                    </div>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ translate('quick_actions_hint', 'Log the most common sales touchpoints without leaving the request.') }}
                    </p>
                </div>
                <button
                    type="button"
                    :class="crmButtonClass('secondary', 'compact')"
                    @click="openDialog"
                >
                    {{ translate('open_dialog', 'Log manually') }}
                </button>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="quickAction in quickActions"
                    :key="quickAction.id"
                    type="button"
                    :class="crmButtonClass('secondary', 'compact')"
                    :disabled="quickActionSubmitting === quickAction.id || manualSubmitting"
                    @click="submitQuickAction(quickAction)"
                >
                    {{ localizeQuickActionLabel(quickAction) }}
                </button>
            </div>

            <div
                v-if="activeNextAction"
                class="mt-3 rounded-sm border border-emerald-200 bg-white p-3 text-sm text-stone-600 dark:border-emerald-500/20 dark:bg-neutral-900 dark:text-neutral-300"
            >
                <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                    {{ translate('current_next_action', 'Current next action') }}
                </div>
                <div class="mt-1 font-medium text-stone-800 dark:text-neutral-100">
                    {{ activityHeadline(activeNextAction) }}
                </div>
                <div
                    class="mt-1 text-xs text-stone-500 dark:text-neutral-400"
                    :title="formatAbsoluteDate(activeNextAction.sales_activity?.due_at)"
                >
                    {{ translate('due_prefix', 'Due') }} {{ formatDate(activeNextAction.sales_activity?.due_at) }}
                </div>
            </div>
        </div>

        <div v-if="activityItems.length" class="mt-4 space-y-3">
            <div
                v-for="item in activityItems"
                :key="item.id"
                class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium" :class="activityBadgeClass(item)">
                                {{ activityLabel(item) }}
                            </span>
                            <span
                                v-for="chip in activitySecondaryChips(item)"
                                :key="`${item.id}-${chip.key}`"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="chip.className"
                                :title="chip.title"
                            >
                                {{ chip.label }}
                            </span>
                        </div>
                        <div
                            v-if="showSubject && item.subject"
                            class="mt-2 text-xs text-stone-500 dark:text-neutral-400"
                        >
                            <Link
                                v-if="itemHref(item)"
                                :href="itemHref(item)"
                                class="font-medium text-stone-600 hover:text-green-700 hover:underline dark:text-neutral-300 dark:hover:text-green-400"
                            >
                                {{ item.subject }}
                            </Link>
                            <span v-else>{{ item.subject }}</span>
                        </div>
                        <div class="mt-2 font-medium text-stone-800 dark:text-neutral-100">
                            {{ activityHeadline(item) }}
                        </div>
                        <p v-if="activityMeta(item)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ activityMeta(item) }}
                        </p>
                        <p v-if="activityBody(item)" class="mt-1 whitespace-pre-line text-sm text-stone-600 dark:text-neutral-300">
                            {{ activityBody(item) }}
                        </p>
                    </div>

                    <div class="shrink-0 text-xs text-stone-500 dark:text-neutral-400 sm:text-right">
                        <div>{{ actorLabel(item) }}</div>
                        <div :title="formatAbsoluteDate(item.created_at)">
                            {{ formatDate(item.created_at) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p v-else class="mt-4 text-sm text-stone-500 dark:text-neutral-400">
            {{ translate('empty', 'No sales activity yet.') }}
        </p>

        <Modal :show="dialogOpen" max-width="3xl" @close="closeOverlay">
            <div class="relative" :data-testid="dialogId">
                <div class="border-b border-stone-200 bg-white px-5 py-5 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4.5 w-4.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M6.75 4.75h10.5A1.75 1.75 0 0 1 19 6.5v11a1.75 1.75 0 0 1-1.75 1.75H6.75A1.75 1.75 0 0 1 5 17.5v-11a1.75 1.75 0 0 1 1.75-1.75Z" />
                                </svg>
                            </div>
                            <div class="space-y-2">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-300">
                                    {{ translate('title', 'Sales activity') }}
                                </p>
                                <h3 class="text-lg font-semibold text-stone-900 dark:text-white">
                                    {{ translate('dialog_title', 'Log a sales activity') }}
                                </h3>
                                <p class="max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                                    {{ translate('dialog_hint', 'Capture notes, outcomes, next actions, and meetings from the request timeline.') }}
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-stone-200 bg-white text-stone-500 transition hover:bg-stone-50 hover:text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="closeOverlay"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>
                </div>

                <form class="space-y-4 bg-stone-50 p-5 dark:bg-neutral-950" @submit.prevent="submitManualAction">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="space-y-4">
                            <FloatingSelect
                                v-model="manualForm.action"
                                :label="translate('fields.action', 'Activity')"
                                :options="manualActionOptions"
                                :placeholder="translate('fields.action_placeholder', 'Choose an activity')"
                            />
                            <InputError :message="manualErrors.action?.[0]" />

                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <FloatingInput
                                    v-model="manualForm.occurred_at"
                                    type="datetime-local"
                                    :label="translate('fields.occurred_at', 'Logged at')"
                                />
                                <FloatingInput
                                    v-if="needsDueAt"
                                    v-model="manualForm.due_at"
                                    type="datetime-local"
                                    :label="translate('fields.due_at', 'Due at')"
                                />
                            </div>
                            <InputError :message="manualErrors.occurred_at?.[0]" />
                            <InputError v-if="needsDueAt" :message="manualErrors.due_at?.[0]" />

                            <FloatingInput
                                v-model="manualForm.description"
                                :label="translate('fields.description', 'Short summary')"
                            />
                            <InputError :message="manualErrors.description?.[0]" />

                            <FloatingTextarea
                                v-model="manualForm.note"
                                :label="translate('fields.note', 'Note')"
                            />
                            <InputError :message="manualErrors.note?.[0]" />
                        </div>
                    </section>

                    <div
                        v-if="feedback.message && feedback.tone === 'error'"
                        class="rounded-sm border px-3 py-2 text-sm"
                        :class="feedbackClass"
                    >
                        {{ feedback.message }}
                    </div>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="crmButtonClass('secondary', 'dialog')"
                            @click="closeOverlay"
                        >
                            {{ translate('cancel', 'Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="crmButtonClass('primary', 'dialog')"
                            :disabled="manualSubmitting"
                        >
                            {{ translate('save', 'Save activity') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </section>
</template>
