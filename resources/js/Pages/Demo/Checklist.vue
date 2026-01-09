<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    steps: {
        type: Array,
        default: () => [],
    },
    progress: {
        type: Array,
        default: () => [],
    },
});

const progressMap = computed(() => {
    const map = {};
    (props.progress || []).forEach((entry) => {
        map[entry.step_key] = entry;
    });
    return map;
});

const groupedSteps = computed(() => {
    const groups = {};
    (props.steps || []).forEach((step) => {
        const group = step.group || 'General';
        if (!groups[group]) {
            groups[group] = [];
        }
        groups[group].push(step);
    });
    return Object.entries(groups).map(([group, items]) => ({
        group,
        items,
    }));
});

const stepStatus = (step) => progressMap.value[step.key]?.status || 'pending';

const statusBadgeClass = (status) => {
    if (status === 'done') {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
    if (status === 'skipped') {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
    }
    return 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300';
};

const statusLabel = (status) => {
    if (status === 'done') {
        return 'Done';
    }
    if (status === 'skipped') {
        return 'Skipped';
    }
    return 'Pending';
};

const totals = computed(() => {
    const total = props.steps.length;
    const done = props.steps.filter((step) => stepStatus(step) === 'done').length;
    const skipped = props.steps.filter((step) => stepStatus(step) === 'skipped').length;
    return { total, done, skipped };
});

const hasMissingParams = (params) => {
    if (!params) {
        return false;
    }
    return Object.values(params).some((value) => value === null || value === undefined || value === '');
};
</script>

<template>
    <Head title="Demo checklist" />
    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-5xl space-y-6">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Demo checklist</h1>
                <p class="text-sm text-stone-600 dark:text-neutral-400">
                    Track your guided demo progress and jump to any step.
                </p>
            </div>

            <div
                data-testid="demo-checklist-summary"
                class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            Progress
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ totals.done }} completed, {{ totals.skipped }} skipped, {{ totals.total }} total steps
                        </div>
                    </div>
                    <div class="text-sm font-semibold text-emerald-600 dark:text-emerald-300">
                        {{ totals.done }} / {{ totals.total }}
                    </div>
                </div>
            </div>

            <div v-for="group in groupedSteps" :key="group.group" class="space-y-3">
                <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                    {{ group.group }}
                </h2>
                <div class="grid gap-3 md:grid-cols-2">
                    <div
                        v-for="step in group.items"
                        :key="step.key"
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ step.title }}
                                </div>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ step.description }}
                                </p>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusBadgeClass(stepStatus(step))">
                                {{ statusLabel(stepStatus(step)) }}
                            </span>
                        </div>
                        <div class="mt-3 flex items-center justify-end gap-2">
                            <Link
                                v-if="step.route_name && !hasMissingParams(step.route_params)"
                                :href="route(step.route_name, step.route_params || {})"
                                class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                            >
                                Go to step
                            </Link>
                            <span
                                v-else
                                class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-1.5 text-xs font-semibold text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                            >
                                Unavailable yet
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
