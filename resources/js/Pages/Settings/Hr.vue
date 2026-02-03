<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import SelectableItem from '@/Components/SelectableItem.vue';

const props = defineProps({
    templates: {
        type: Array,
        default: () => [],
    },
    default_template: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const editingId = ref(null);
const localBreakError = ref('');

const resolvedDefaults = computed(() => ({
    start_time: props.default_template?.start_time || '09:00',
    end_time: props.default_template?.end_time || '17:00',
    break_minutes: Number(props.default_template?.break_minutes ?? 60),
    days_of_week: Array.isArray(props.default_template?.days_of_week)
        ? props.default_template.days_of_week
        : [],
}));

const form = useForm({
    position_title: '',
    start_time: resolvedDefaults.value.start_time,
    end_time: resolvedDefaults.value.end_time,
    breaks: resolvedDefaults.value.break_minutes ? [resolvedDefaults.value.break_minutes] : [],
    days_of_week: [],
    is_active: true,
});

const dayOptions = computed(() => ([
    { value: 'mo', label: t('planning.weekdays.mo') },
    { value: 'tu', label: t('planning.weekdays.tu') },
    { value: 'we', label: t('planning.weekdays.we') },
    { value: 'th', label: t('planning.weekdays.th') },
    { value: 'fr', label: t('planning.weekdays.fr') },
    { value: 'sa', label: t('planning.weekdays.sa') },
    { value: 'su', label: t('planning.weekdays.su') },
]));

const breaksTotal = computed(() =>
    (form.breaks || []).reduce((total, value) => total + (Number(value) || 0), 0)
);

const breakError = computed(() => {
    if (localBreakError.value) {
        return localBreakError.value;
    }
    if (form.errors.breaks) {
        return form.errors.breaks;
    }
    const key = Object.keys(form.errors || {}).find((item) => item.startsWith('breaks.'));
    return key ? form.errors[key] : '';
});

const templateRows = computed(() => props.templates || []);

const formatDays = (days) => {
    if (!Array.isArray(days) || !days.length) {
        return '-';
    }
    const labelMap = new Map(dayOptions.value.map((item) => [item.value, item.label]));
    return days.map((day) => labelMap.get(day) || day).join(', ');
};

const resetForm = () => {
    editingId.value = null;
    localBreakError.value = '';
    form.reset();
    form.position_title = '';
    form.start_time = resolvedDefaults.value.start_time;
    form.end_time = resolvedDefaults.value.end_time;
    form.breaks = resolvedDefaults.value.break_minutes ? [resolvedDefaults.value.break_minutes] : [];
    form.days_of_week = [];
    form.is_active = true;
};

const addBreak = () => {
    form.breaks = [...(form.breaks || []), 0];
};

const removeBreak = (index) => {
    form.breaks = (form.breaks || []).filter((_, i) => i !== index);
};

const editTemplate = (template) => {
    if (template?.is_global) {
        return;
    }
    editingId.value = template.id;
    localBreakError.value = '';
    form.clearErrors();
    form.position_title = template.position_title || '';
    form.start_time = template.start_time || resolvedDefaults.value.start_time;
    form.end_time = template.end_time || resolvedDefaults.value.end_time;
    form.breaks = Array.isArray(template.breaks) && template.breaks.length
        ? [...template.breaks]
        : (template.break_minutes ? [template.break_minutes] : []);
    form.days_of_week = Array.isArray(template.days_of_week) ? [...template.days_of_week] : [];
    form.is_active = template.is_active ?? true;
};

const submit = () => {
    localBreakError.value = '';
    if (breaksTotal.value > 60) {
        localBreakError.value = t('settings.hr.form.break_total', { minutes: breaksTotal.value });
        return;
    }

    form.transform((data) => ({
        ...data,
        breaks: (data.breaks || []).map((value) => Number(value) || 0).filter((value) => value > 0),
        days_of_week: Array.isArray(data.days_of_week) ? data.days_of_week : [],
    }));

    if (editingId.value) {
        form.patch(route('settings.hr.shift-templates.update', editingId.value), {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        });
        return;
    }

    form.post(route('settings.hr.shift-templates.store'), {
        preserveScroll: true,
        onSuccess: () => resetForm(),
    });
};

const removeTemplate = (template) => {
    if (!template || template.is_global) {
        return;
    }
    if (!window.confirm(t('settings.hr.confirm.delete'))) {
        return;
    }
    router.delete(route('settings.hr.shift-templates.destroy', template.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (editingId.value === template.id) {
                resetForm();
            }
        },
    });
};

watch(() => breaksTotal.value, () => {
    if (localBreakError.value) {
        localBreakError.value = '';
    }
});
</script>

<template>
    <Head :title="t('settings.hr.meta_title')" />

    <SettingsLayout active="hr" content-class="w-[1400px] max-w-full">
        <div class="space-y-4">
            <div>
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                    {{ t('settings.hr.title') }}
                </h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ t('settings.hr.subtitle') }}
                </p>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('settings.hr.sections.templates.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ t('settings.hr.sections.templates.description') }}
                        </p>
                    </div>

                    <form class="mt-4 space-y-3" @submit.prevent="submit">
                        <div>
                            <FloatingInput v-model="form.position_title" :label="t('settings.hr.form.position')" />
                            <InputError class="mt-1" :message="form.errors.position_title" />
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <FloatingInput v-model="form.start_time" type="time" :label="t('settings.hr.form.start_time')" />
                                <InputError class="mt-1" :message="form.errors.start_time" />
                            </div>
                            <div>
                                <FloatingInput v-model="form.end_time" type="time" :label="t('settings.hr.form.end_time')" />
                                <InputError class="mt-1" :message="form.errors.end_time" />
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                {{ t('settings.hr.form.breaks') }}
                            </label>
                            <div v-for="(value, index) in form.breaks" :key="`break-${index}`" class="flex items-center gap-2">
                                <FloatingInput
                                    v-model="form.breaks[index]"
                                    type="number"
                                    min="0"
                                    max="60"
                                    :label="`${t('settings.hr.form.breaks')} ${index + 1}`"
                                />
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-rose-600 hover:text-rose-700"
                                    @click="removeBreak(index)"
                                >
                                    {{ t('settings.hr.form.remove_break') }}
                                </button>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center text-xs font-semibold text-emerald-600 hover:text-emerald-700"
                                @click="addBreak"
                            >
                                {{ t('settings.hr.form.add_break') }}
                            </button>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('settings.hr.form.break_total', { minutes: breaksTotal }) }}
                            </p>
                            <InputError class="mt-1" :message="breakError" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                {{ t('settings.hr.form.days') }}
                            </label>
                            <SelectableItem v-model="form.days_of_week" :LoopValue="dayOptions" />
                        </div>

                        <label class="flex items-center gap-2 text-sm text-stone-600 dark:text-neutral-300">
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-900"
                            />
                            {{ t('settings.hr.form.active') }}
                        </label>

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                                :disabled="form.processing"
                            >
                                {{ editingId ? t('settings.hr.form.update') : t('settings.hr.form.create') }}
                            </button>
                            <button
                                v-if="editingId"
                                type="button"
                                class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="resetForm"
                            >
                                {{ t('settings.hr.form.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('settings.hr.sections.defaults.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ t('settings.hr.sections.defaults.description') }}
                        </p>
                    </div>
                    <div class="mt-4 space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                        <div class="flex justify-between">
                            <span>{{ t('settings.hr.form.start_time') }}</span>
                            <span class="font-semibold">{{ resolvedDefaults.start_time }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>{{ t('settings.hr.form.end_time') }}</span>
                            <span class="font-semibold">{{ resolvedDefaults.end_time }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>{{ t('settings.hr.form.breaks') }}</span>
                            <span class="font-semibold">{{ resolvedDefaults.break_minutes }} min</span>
                        </div>
                        <div class="flex justify-between">
                            <span>{{ t('settings.hr.form.days') }}</span>
                            <span class="font-semibold">
                                {{ resolvedDefaults.days_of_week.length ? formatDays(resolvedDefaults.days_of_week) : '-' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                        {{ t('settings.hr.sections.templates.title') }}
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-stone-50 text-xs uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ t('settings.hr.table.position') }}</th>
                                <th class="px-4 py-2 text-left">{{ t('settings.hr.table.time') }}</th>
                                <th class="px-4 py-2 text-left">{{ t('settings.hr.table.breaks') }}</th>
                                <th class="px-4 py-2 text-left">{{ t('settings.hr.table.days') }}</th>
                                <th class="px-4 py-2 text-left">{{ t('settings.hr.table.status') }}</th>
                                <th class="px-4 py-2 text-right">{{ t('settings.hr.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody v-if="templateRows.length" class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="template in templateRows" :key="template.id">
                                <td class="px-4 py-2 font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ template.position_title }}
                                    <span
                                        class="ml-2 rounded-full border border-stone-200 px-2 py-0.5 text-[10px] uppercase text-stone-400 dark:border-neutral-700 dark:text-neutral-400"
                                    >
                                        {{ template.is_global ? t('settings.hr.table.global') : t('settings.hr.table.company') }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-stone-600 dark:text-neutral-300">
                                    {{ template.start_time }} - {{ template.end_time }}
                                </td>
                                <td class="px-4 py-2 text-stone-600 dark:text-neutral-300">
                                    {{ template.break_minutes }} min
                                </td>
                                <td class="px-4 py-2 text-stone-600 dark:text-neutral-300">
                                    {{ formatDays(template.days_of_week) }}
                                </td>
                                <td class="px-4 py-2 text-stone-600 dark:text-neutral-300">
                                    {{ template.is_active ? t('settings.hr.table.active') : t('settings.hr.table.inactive') }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <button
                                        type="button"
                                        class="mr-2 text-xs font-semibold text-emerald-600 hover:text-emerald-700 disabled:opacity-50"
                                        :disabled="template.is_global"
                                        @click="editTemplate(template)"
                                    >
                                        {{ t('jobs.actions.edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-rose-600 hover:text-rose-700 disabled:opacity-50"
                                        :disabled="template.is_global"
                                        @click="removeTemplate(template)"
                                    >
                                        {{ t('tasks.actions.delete') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else>
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                                    {{ t('settings.hr.messages.empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>



