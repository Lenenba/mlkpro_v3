<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';

const { t } = useI18n();

const props = defineProps({
    timezone: {
        type: String,
        default: 'UTC',
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    weeklyAvailabilities: {
        type: Array,
        default: () => [],
    },
    exceptions: {
        type: Array,
        default: () => [],
    },
    accountSettings: {
        type: Object,
        default: () => ({}),
    },
    teamSettings: {
        type: Array,
        default: () => [],
    },
    notificationSettings: {
        type: Object,
        default: () => ({}),
    },
});

const dayOptions = computed(() => ([
    { value: 0, label: t('planning.weekdays.su') },
    { value: 1, label: t('planning.weekdays.mo') },
    { value: 2, label: t('planning.weekdays.tu') },
    { value: 3, label: t('planning.weekdays.we') },
    { value: 4, label: t('planning.weekdays.th') },
    { value: 5, label: t('planning.weekdays.fr') },
    { value: 6, label: t('planning.weekdays.sa') },
]));

const memberOptions = computed(() => (props.teamMembers || []).map((member) => ({
    value: String(member.id),
    label: member.title ? `${member.name} - ${member.title}` : member.name,
})));

const exceptionMemberOptions = computed(() => [
    { value: '', label: t('settings.reservations.exceptions.all_members') },
    ...memberOptions.value,
]);

const teamSettingMemberOptions = computed(() => [
    { value: '', label: t('settings.reservations.team_settings.select_member') },
    ...memberOptions.value,
]);

const typeOptions = computed(() => ([
    { value: 'closed', label: t('settings.reservations.exceptions.types.closed') },
    { value: 'open', label: t('settings.reservations.exceptions.types.open') },
]));

const reminderHourOptions = computed(() => ([
    { value: 24, label: t('settings.reservations.notifications.reminders.day_before') },
    { value: 2, label: t('settings.reservations.notifications.reminders.two_hours') },
    { value: 1, label: t('settings.reservations.notifications.reminders.one_hour') },
]));

const form = useForm({
    account_settings: {
        buffer_minutes: props.accountSettings?.buffer_minutes ?? 0,
        slot_interval_minutes: props.accountSettings?.slot_interval_minutes ?? 30,
        min_notice_minutes: props.accountSettings?.min_notice_minutes ?? 0,
        max_advance_days: props.accountSettings?.max_advance_days ?? 90,
        cancellation_cutoff_hours: props.accountSettings?.cancellation_cutoff_hours ?? 12,
        allow_client_cancel: Boolean(props.accountSettings?.allow_client_cancel ?? true),
        allow_client_reschedule: Boolean(props.accountSettings?.allow_client_reschedule ?? true),
    },
    team_settings: (props.teamSettings || []).map((item) => ({
        team_member_id: String(item.team_member_id),
        buffer_minutes: item.buffer_minutes ?? null,
        slot_interval_minutes: item.slot_interval_minutes ?? null,
        min_notice_minutes: item.min_notice_minutes ?? null,
        max_advance_days: item.max_advance_days ?? null,
        cancellation_cutoff_hours: item.cancellation_cutoff_hours ?? null,
        allow_client_cancel: Boolean(item.allow_client_cancel ?? true),
        allow_client_reschedule: Boolean(item.allow_client_reschedule ?? true),
    })),
    weekly_availabilities: (props.weeklyAvailabilities || []).map((item) => ({
        team_member_id: String(item.team_member_id),
        day_of_week: Number(item.day_of_week),
        start_time: item.start_time,
        end_time: item.end_time,
        is_active: Boolean(item.is_active ?? true),
    })),
    exceptions: (props.exceptions || []).map((item) => ({
        id: item.id ?? null,
        team_member_id: item.team_member_id ? String(item.team_member_id) : '',
        date: item.date,
        start_time: item.start_time || '',
        end_time: item.end_time || '',
        type: item.type || 'closed',
        reason: item.reason || '',
    })),
    notification_settings: {
        enabled: Boolean(props.notificationSettings?.enabled ?? true),
        email: Boolean(props.notificationSettings?.email ?? true),
        in_app: Boolean(props.notificationSettings?.in_app ?? true),
        notify_on_created: Boolean(props.notificationSettings?.notify_on_created ?? true),
        notify_on_rescheduled: Boolean(props.notificationSettings?.notify_on_rescheduled ?? true),
        notify_on_cancelled: Boolean(props.notificationSettings?.notify_on_cancelled ?? true),
        notify_on_completed: Boolean(props.notificationSettings?.notify_on_completed ?? true),
        notify_on_reminder: Boolean(props.notificationSettings?.notify_on_reminder ?? true),
        notify_on_review_submitted: Boolean(props.notificationSettings?.notify_on_review_submitted ?? true),
        review_request_on_completed: Boolean(props.notificationSettings?.review_request_on_completed ?? true),
        reminder_hours: Array.isArray(props.notificationSettings?.reminder_hours)
            ? props.notificationSettings.reminder_hours.map((value) => Number(value)).filter((value) => Number.isInteger(value))
            : [24, 2],
    },
});

const teamSettingDraft = ref({
    team_member_id: '',
});

const availabilityDraft = ref({
    team_member_id: '',
    day_of_week: 1,
    start_time: '09:00',
    end_time: '17:00',
    is_active: true,
});

const exceptionDraft = ref({
    team_member_id: '',
    date: '',
    start_time: '',
    end_time: '',
    type: 'closed',
    reason: '',
});

const summaryCards = computed(() => ([
    {
        key: 'timezone',
        label: t('settings.reservations.summary.timezone'),
        value: props.timezone || 'UTC',
        border: 'border-t-indigo-600',
    },
    {
        key: 'team',
        label: t('settings.reservations.summary.team_members'),
        value: Number(props.teamMembers?.length || 0).toLocaleString(),
        border: 'border-t-emerald-600',
    },
    {
        key: 'weekly',
        label: t('settings.reservations.summary.weekly_rules'),
        value: Number(form.weekly_availabilities?.length || 0).toLocaleString(),
        border: 'border-t-amber-500',
    },
    {
        key: 'exceptions',
        label: t('settings.reservations.summary.exceptions'),
        value: Number(form.exceptions?.length || 0).toLocaleString(),
        border: 'border-t-rose-600',
    },
]));

const addTeamSetting = () => {
    const teamMemberId = String(teamSettingDraft.value.team_member_id || '');
    if (!teamMemberId) {
        return;
    }

    const exists = form.team_settings.some((item) => String(item.team_member_id) === teamMemberId);
    if (exists) {
        return;
    }

    form.team_settings.push({
        team_member_id: teamMemberId,
        buffer_minutes: null,
        slot_interval_minutes: null,
        min_notice_minutes: null,
        max_advance_days: null,
        cancellation_cutoff_hours: null,
        allow_client_cancel: true,
        allow_client_reschedule: true,
    });

    teamSettingDraft.value.team_member_id = '';
};

const removeTeamSetting = (index) => {
    form.team_settings.splice(index, 1);
};

const addAvailability = () => {
    if (!availabilityDraft.value.team_member_id) {
        return;
    }

    form.weekly_availabilities.push({
        team_member_id: availabilityDraft.value.team_member_id,
        day_of_week: Number(availabilityDraft.value.day_of_week),
        start_time: availabilityDraft.value.start_time,
        end_time: availabilityDraft.value.end_time,
        is_active: Boolean(availabilityDraft.value.is_active),
    });
};

const removeAvailability = (index) => {
    form.weekly_availabilities.splice(index, 1);
};

const addException = () => {
    if (!exceptionDraft.value.date) {
        return;
    }

    form.exceptions.push({
        id: null,
        team_member_id: exceptionDraft.value.team_member_id,
        date: exceptionDraft.value.date,
        start_time: exceptionDraft.value.start_time,
        end_time: exceptionDraft.value.end_time,
        type: exceptionDraft.value.type,
        reason: exceptionDraft.value.reason,
    });

    exceptionDraft.value = {
        team_member_id: '',
        date: '',
        start_time: '',
        end_time: '',
        type: 'closed',
        reason: '',
    };
};

const removeException = (index) => {
    form.exceptions.splice(index, 1);
};

const toggleReminderHour = (hour) => {
    const hours = new Set((form.notification_settings.reminder_hours || []).map((value) => Number(value)));
    if (hours.has(hour)) {
        hours.delete(hour);
    } else {
        hours.add(hour);
    }

    form.notification_settings.reminder_hours = Array.from(hours).sort((left, right) => right - left);
};

const hasReminderHour = (hour) => (form.notification_settings.reminder_hours || []).includes(hour);

const dayLabel = (day) => dayOptions.value.find((item) => Number(item.value) === Number(day))?.label || day;
const memberLabel = (teamMemberId) => memberOptions.value.find((item) => item.value === String(teamMemberId))?.label || t('settings.reservations.member_unknown');

const submit = () => {
    form.transform((data) => ({
        ...data,
        team_settings: (data.team_settings || []).map((item) => ({
            ...item,
            team_member_id: Number(item.team_member_id),
        })),
        weekly_availabilities: (data.weekly_availabilities || []).map((item) => ({
            ...item,
            team_member_id: Number(item.team_member_id),
            day_of_week: Number(item.day_of_week),
        })),
        exceptions: (data.exceptions || []).map((item) => ({
            ...item,
            team_member_id: item.team_member_id ? Number(item.team_member_id) : null,
        })),
        notification_settings: {
            ...data.notification_settings,
            reminder_hours: (data.notification_settings?.reminder_hours || [])
                .map((value) => Number(value))
                .filter((value) => Number.isInteger(value) && value >= 1 && value <= 168),
        },
    }));

    form.put(route('settings.reservations.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('settings.reservations.meta_title')" />

    <SettingsLayout active="reservations" content-class="w-[1400px] max-w-full">
        <div class="space-y-4">
            <div>
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('settings.reservations.title') }}
                </h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('settings.reservations.subtitle') }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-2 md:grid-cols-4 md:gap-3 lg:gap-5">
                <div
                    v-for="card in summaryCards"
                    :key="`reservation-summary-${card.key}`"
                    class="rounded-sm border border-stone-200 border-t-4 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800"
                    :class="card.border"
                >
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</div>
                </div>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('settings.reservations.company_rules.title') }}</h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('settings.reservations.company_rules.description') }}</p>

                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        <FloatingInput v-model="form.account_settings.buffer_minutes" type="number" min="0" :label="$t('settings.reservations.fields.buffer_minutes')" />
                        <FloatingInput v-model="form.account_settings.slot_interval_minutes" type="number" min="5" :label="$t('settings.reservations.fields.slot_interval_minutes')" />
                        <FloatingInput v-model="form.account_settings.min_notice_minutes" type="number" min="0" :label="$t('settings.reservations.fields.min_notice_minutes')" />
                        <FloatingInput v-model="form.account_settings.max_advance_days" type="number" min="1" :label="$t('settings.reservations.fields.max_advance_days')" />
                        <FloatingInput v-model="form.account_settings.cancellation_cutoff_hours" type="number" min="0" :label="$t('settings.reservations.fields.cancellation_cutoff_hours')" />
                    </div>
                    <div class="mt-3 flex flex-wrap gap-4 text-sm text-stone-700 dark:text-neutral-200">
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.account_settings.allow_client_cancel" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.fields.allow_client_cancel') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.account_settings.allow_client_reschedule" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.fields.allow_client_reschedule') }}
                        </label>
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('settings.reservations.team_settings.title') }}</h2>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('settings.reservations.team_settings.description') }}</p>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 md:grid-cols-[minmax(0,1fr)_auto]">
                        <FloatingSelect
                            v-model="teamSettingDraft.team_member_id"
                            :options="teamSettingMemberOptions"
                            :label="$t('settings.reservations.team_settings.select_member')"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                            @click="addTeamSetting"
                        >
                            {{ $t('settings.reservations.team_settings.add') }}
                        </button>
                    </div>

                    <div class="mt-3 space-y-3">
                        <div
                            v-for="(item, index) in form.team_settings"
                            :key="`team-setting-${item.team_member_id}-${index}`"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ memberLabel(item.team_member_id) }}</div>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-rose-600 hover:text-rose-700"
                                    @click="removeTeamSetting(index)"
                                >
                                    {{ $t('settings.reservations.remove') }}
                                </button>
                            </div>
                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                <FloatingInput v-model="item.buffer_minutes" type="number" min="0" :label="$t('settings.reservations.fields.buffer_minutes')" />
                                <FloatingInput v-model="item.slot_interval_minutes" type="number" min="5" :label="$t('settings.reservations.fields.slot_interval_minutes')" />
                                <FloatingInput v-model="item.min_notice_minutes" type="number" min="0" :label="$t('settings.reservations.fields.min_notice_minutes')" />
                                <FloatingInput v-model="item.max_advance_days" type="number" min="1" :label="$t('settings.reservations.fields.max_advance_days')" />
                                <FloatingInput v-model="item.cancellation_cutoff_hours" type="number" min="0" :label="$t('settings.reservations.fields.cancellation_cutoff_hours')" />
                            </div>
                            <div class="mt-3 flex flex-wrap gap-4 text-sm text-stone-700 dark:text-neutral-200">
                                <label class="inline-flex items-center gap-2">
                                    <input v-model="item.allow_client_cancel" type="checkbox" class="rounded border-stone-300">
                                    {{ $t('settings.reservations.fields.allow_client_cancel') }}
                                </label>
                                <label class="inline-flex items-center gap-2">
                                    <input v-model="item.allow_client_reschedule" type="checkbox" class="rounded border-stone-300">
                                    {{ $t('settings.reservations.fields.allow_client_reschedule') }}
                                </label>
                            </div>
                        </div>

                        <div v-if="!form.team_settings.length" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-3 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ $t('settings.reservations.team_settings.empty') }}
                        </div>
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('settings.reservations.weekly.title') }}</h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('settings.reservations.weekly.description') }}</p>
                    <div class="mt-3 grid gap-3 md:grid-cols-5">
                        <FloatingSelect v-model="availabilityDraft.team_member_id" :options="memberOptions" :label="$t('settings.reservations.fields.team_member')" />
                        <FloatingSelect v-model="availabilityDraft.day_of_week" :options="dayOptions" :label="$t('settings.reservations.fields.day')" />
                        <FloatingInput v-model="availabilityDraft.start_time" type="time" :label="$t('settings.reservations.fields.start_time')" />
                        <FloatingInput v-model="availabilityDraft.end_time" type="time" :label="$t('settings.reservations.fields.end_time')" />
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                            @click="addAvailability"
                        >
                            {{ $t('settings.reservations.add') }}
                        </button>
                    </div>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="(item, index) in form.weekly_availabilities"
                            :key="`weekly-${index}`"
                            class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700"
                        >
                            <span>{{ memberLabel(item.team_member_id) }} · {{ dayLabel(item.day_of_week) }} · {{ item.start_time }} - {{ item.end_time }}</span>
                            <button type="button" class="text-rose-600" @click="removeAvailability(index)">{{ $t('settings.reservations.remove') }}</button>
                        </div>
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('settings.reservations.exceptions.title') }}</h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('settings.reservations.exceptions.description') }}</p>
                    <div class="mt-3 grid gap-3 md:grid-cols-7">
                        <FloatingSelect v-model="exceptionDraft.team_member_id" :options="exceptionMemberOptions" :label="$t('settings.reservations.fields.team_member')" />
                        <FloatingInput v-model="exceptionDraft.date" type="date" :label="$t('settings.reservations.fields.date')" />
                        <FloatingInput v-model="exceptionDraft.start_time" type="time" :label="$t('settings.reservations.fields.start_time_optional')" />
                        <FloatingInput v-model="exceptionDraft.end_time" type="time" :label="$t('settings.reservations.fields.end_time_optional')" />
                        <FloatingSelect v-model="exceptionDraft.type" :options="typeOptions" :label="$t('settings.reservations.fields.type')" />
                        <FloatingInput v-model="exceptionDraft.reason" :label="$t('settings.reservations.fields.reason')" />
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                            @click="addException"
                        >
                            {{ $t('settings.reservations.add') }}
                        </button>
                    </div>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="(item, index) in form.exceptions"
                            :key="`exception-${item.id || index}`"
                            class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700"
                        >
                            <span>
                                {{ item.date }}
                                · {{ item.team_member_id ? memberLabel(item.team_member_id) : $t('settings.reservations.exceptions.all_members') }}
                                · {{ item.type }}
                                {{ item.start_time && item.end_time ? `(${item.start_time}-${item.end_time})` : $t('settings.reservations.exceptions.all_day') }}
                                · {{ item.reason || '-' }}
                            </span>
                            <button type="button" class="text-rose-600" @click="removeException(index)">{{ $t('settings.reservations.remove') }}</button>
                        </div>
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('settings.reservations.notifications.title') }}</h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('settings.reservations.notifications.description') }}</p>

                    <div class="mt-3 grid gap-2 md:grid-cols-2 text-sm text-stone-700 dark:text-neutral-200">
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.enabled" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.enabled') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.email" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.email') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.in_app" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.in_app') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.notify_on_created" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.notify_on_created') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.notify_on_rescheduled" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.notify_on_rescheduled') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.notify_on_cancelled" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.notify_on_cancelled') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.notify_on_completed" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.notify_on_completed') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.notify_on_review_submitted" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.notify_on_review_submitted') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.notify_on_reminder" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.notify_on_reminder') }}
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.notification_settings.review_request_on_completed" type="checkbox" class="rounded border-stone-300">
                            {{ $t('settings.reservations.notifications.fields.review_request_on_completed') }}
                        </label>
                    </div>

                    <div class="mt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.reservations.notifications.fields.reminder_hours') }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                v-for="option in reminderHourOptions"
                                :key="`reminder-hour-${option.value}`"
                                type="button"
                                class="rounded-sm border px-3 py-1.5 text-xs font-semibold"
                                :class="hasReminderHour(option.value)
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-stone-300 text-stone-700 dark:border-neutral-700 dark:text-neutral-200'"
                                @click="toggleReminderHour(option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <InputError :message="form.errors.weekly_availabilities || form.errors.exceptions || form.errors.team_settings || form.errors.notification_settings" />
                    <div class="flex justify-end">
                        <button type="submit" class="rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="form.processing">
                            {{ form.processing ? $t('settings.reservations.saving') : $t('settings.reservations.save') }}
                        </button>
                    </div>
                </section>
            </form>
        </div>
    </SettingsLayout>
</template>
