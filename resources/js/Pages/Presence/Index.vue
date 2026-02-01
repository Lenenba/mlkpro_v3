<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/UI/Card.vue';
import { defaultAvatarIcon } from '@/utils/iconPresets';

const props = defineProps({
    people: {
        type: Array,
        default: () => [],
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
    permissions: {
        type: Object,
        default: () => ({}),
    },
    self_id: {
        type: Number,
        default: null,
    },
    company: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const people = ref([...(props.people || [])]);
const processing = ref(false);
const processingAction = ref(null);
const error = ref('');

const manualAllowed = computed(() => Boolean(props.permissions?.can_clock));
const selfPerson = computed(() => people.value.find((person) => person.id === props.self_id) || null);

const isClockedIn = (person) => person?.status === 'clocked_in';

const formatDateTime = (value) => {
    if (!value) {
        return t('presence.labels.none');
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return t('presence.labels.none');
    }
    return date.toLocaleString();
};

const resolveOutTime = (person) => {
    if (!person) {
        return null;
    }
    if (person.status === 'clocked_in') {
        return person.last_clock_out_at || null;
    }
    return person.clock_out_at || person.last_clock_out_at || null;
};

const statusLabel = (person) => {
    if (!person || !person.status) {
        return t('presence.status.no_activity');
    }
    return t(`presence.status.${person.status}`);
};

const statusBadgeClass = (person) => {
    if (person?.status === 'clocked_in') {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200';
    }
    if (person?.status === 'clocked_out') {
        return 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300';
    }
    return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200';
};

const formatRole = (role) => {
    if (!role) {
        return t('presence.labels.role_fallback');
    }
    return String(role).replace(/_/g, ' ');
};

const formatMethod = (method) => {
    if (!method) {
        return t('presence.labels.none');
    }
    return String(method).replace(/_/g, ' ');
};

const updatePerson = (payload) => {
    if (!payload || !payload.id) {
        return;
    }
    const index = people.value.findIndex((person) => person.id === payload.id);
    if (index >= 0) {
        people.value[index] = { ...people.value[index], ...payload };
        return;
    }
    people.value.push(payload);
};

const clockInDisabled = computed(() =>
    !manualAllowed.value || !selfPerson.value || isClockedIn(selfPerson.value) || processing.value,
);
const clockOutDisabled = computed(() =>
    !manualAllowed.value || !selfPerson.value || !isClockedIn(selfPerson.value) || processing.value,
);
const clockInLabel = computed(() =>
    processingAction.value === 'in' ? t('presence.actions.clocking_in') : t('presence.actions.clock_in'),
);
const clockOutLabel = computed(() =>
    processingAction.value === 'out' ? t('presence.actions.clocking_out') : t('presence.actions.clock_out'),
);

const clockIn = async () => {
    if (clockInDisabled.value) {
        return;
    }
    processing.value = true;
    processingAction.value = 'in';
    error.value = '';

    try {
        const response = await axios.post(route('presence.clock-in'));
        updatePerson(response?.data?.person);
    } catch (err) {
        error.value = err?.response?.data?.message || t('presence.errors.clock_in');
    } finally {
        processing.value = false;
        processingAction.value = null;
    }
};

const clockOut = async () => {
    if (clockOutDisabled.value) {
        return;
    }
    processing.value = true;
    processingAction.value = 'out';
    error.value = '';

    try {
        const response = await axios.post(route('presence.clock-out'));
        updatePerson(response?.data?.person);
    } catch (err) {
        error.value = err?.response?.data?.message || t('presence.errors.clock_out');
    } finally {
        processing.value = false;
        processingAction.value = null;
    }
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('presence.title')" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('presence.title') }}
                    </h1>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('presence.subtitle') }}
                    </p>
                </div>
                <div v-if="selfPerson && manualAllowed" class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-sm border border-emerald-200 bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="clockInDisabled"
                        @click="clockIn"
                    >
                        {{ clockInLabel }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 shadow-sm transition hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :disabled="clockOutDisabled"
                        @click="clockOut"
                    >
                        {{ clockOutLabel }}
                    </button>
                </div>
            </div>

            <div v-if="error" class="rounded-sm border border-red-200 bg-red-50 p-3 text-xs text-red-700">
                {{ error }}
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <Card>
                    <template #title>{{ t('presence.cards.settings') }}</template>
                    <div class="space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                        <div class="flex items-center justify-between">
                            <span>{{ t('presence.labels.auto_clock_in') }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                :class="settings?.auto_clock_in
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200'
                                    : 'bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300'"
                            >
                                {{ settings?.auto_clock_in ? t('presence.labels.enabled') : t('presence.labels.disabled') }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('presence.labels.auto_clock_out') }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                :class="settings?.auto_clock_out
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200'
                                    : 'bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300'"
                            >
                                {{ settings?.auto_clock_out ? t('presence.labels.enabled') : t('presence.labels.disabled') }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('presence.labels.manual_clock') }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                :class="settings?.manual_clock
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200'
                                    : 'bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300'"
                            >
                                {{ settings?.manual_clock ? t('presence.labels.enabled') : t('presence.labels.disabled') }}
                            </span>
                        </div>
                    </div>
                </Card>

                <Card>
                    <template #title>{{ t('presence.cards.me') }}</template>
                    <div v-if="selfPerson" class="space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                        <div class="flex items-center justify-between">
                            <span>{{ t('presence.labels.status') }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="statusBadgeClass(selfPerson)">
                                {{ statusLabel(selfPerson) }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('presence.labels.last_in') }}</span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatDateTime(selfPerson.clock_in_at) }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('presence.labels.last_out') }}</span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatDateTime(resolveOutTime(selfPerson)) }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('presence.labels.method') }}</span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatMethod(selfPerson.method) }}
                            </span>
                        </div>
                        <p v-if="!manualAllowed" class="text-xs text-amber-600 dark:text-amber-300">
                            {{ t('presence.help.manual_disabled') }}
                        </p>
                    </div>
                    <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('presence.empty') }}
                    </div>
                </Card>
            </div>

            <Card>
                <template #title>{{ t('presence.cards.team') }}</template>
                <div v-if="!people.length" class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('presence.empty') }}
                </div>
                <div v-else class="space-y-2">
                    <div
                        v-for="person in people"
                        :key="person.id"
                        class="flex flex-wrap items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div class="h-10 w-10 overflow-hidden rounded-full border border-stone-200 bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800">
                                <img
                                    :src="person.profile_picture_url || defaultAvatarIcon"
                                    :alt="person.name"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-stone-800 dark:text-neutral-100">{{ person.name }}</p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatRole(person.role) }}
                                    <span v-if="person.title"> · {{ person.title }}</span>
                                </p>
                            </div>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="statusBadgeClass(person)">
                            {{ statusLabel(person) }}
                        </span>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            <div>{{ t('presence.labels.last_in') }}: {{ formatDateTime(person.clock_in_at) }}</div>
                            <div>{{ t('presence.labels.last_out') }}: {{ formatDateTime(resolveOutTime(person)) }}</div>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
