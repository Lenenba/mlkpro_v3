<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    workspace: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    poll_interval_ms: { type: Number, default: 1500 },
});

const isRefreshing = ref(false);
const lastRefreshAt = ref(new Date());
let pollTimer = null;
let redirectTimer = null;

const normalizedFilters = computed(() => {
    const query = {};

    if (props.filters?.status && props.filters.status !== 'all') {
        query.status = props.filters.status;
    }

    if (props.filters?.sales_status && props.filters.sales_status !== 'all') {
        query.sales_status = props.filters.sales_status;
    }

    if (props.filters?.page) {
        query.page = props.filters.page;
    }

    if (props.filters?.per_page && Number(props.filters.per_page) !== 10) {
        query.per_page = props.filters.per_page;
    }

    return query;
});

const detailsHref = computed(() => route('superadmin.demo-workspaces.show', {
    demoWorkspace: props.workspace.id,
    ...normalizedFilters.value,
}));

const listHref = computed(() => route('superadmin.demo-workspaces.index', normalizedFilters.value));
const status = computed(() => String(props.workspace.provisioning_status || props.workspace.status || 'queued'));
const progress = computed(() => Math.max(0, Math.min(100, Number(props.workspace.provisioning_progress || 0))));
const isReady = computed(() => status.value === 'ready');
const isFailed = computed(() => status.value === 'failed');
const isTerminal = computed(() => isReady.value || isFailed.value || status.value === 'purged');

const headline = computed(() => {
    if (isReady.value) {
        return 'Demo workspace ready';
    }

    if (isFailed.value) {
        return 'Provisioning needs attention';
    }

    if (status.value === 'queued') {
        return 'Waiting for provisioning';
    }

    return 'Provisioning demo workspace';
});

const supportingText = computed(() => {
    if (isReady.value) {
        return 'The tenant, data, credentials, and access kit are finalized. Opening the workspace details now.';
    }

    if (isFailed.value) {
        return 'The latest attempt stopped before the demo was ready. Review the captured error before running it again.';
    }

    if (status.value === 'queued') {
        return 'The request is queued and waiting for the demo provisioning worker to pick it up.';
    }

    return 'The tenant access, realistic sample data, role logins, and handoff details are being prepared.';
});

const stageLabel = computed(() => props.workspace.provisioning_stage || (
    status.value === 'queued' ? 'Queued for provisioning' : 'Preparing demo workspace'
));

const steps = computed(() => [
    {
        threshold: 5,
        label: 'Queued',
        description: 'Provisioning request accepted.',
    },
    {
        threshold: 15,
        label: 'Tenant access',
        description: props.workspace.provisioning_stage === 'Resetting tenant access'
            ? 'Refreshing the existing tenant access.'
            : 'Creating the demo tenant and primary login.',
    },
    {
        threshold: 60,
        label: 'Sample data',
        description: 'Generating realistic records for the selected modules.',
    },
    {
        threshold: 85,
        label: 'Access kit',
        description: 'Preparing credentials, extra roles, and final checks.',
    },
    {
        threshold: 100,
        label: 'Ready',
        description: 'Demo finalized and ready to open.',
    },
]);

const stepState = (step, index) => {
    if (progress.value >= step.threshold) {
        return 'complete';
    }

    const previousThreshold = steps.value[index - 1]?.threshold ?? 0;

    if (!isFailed.value && progress.value >= previousThreshold) {
        return 'active';
    }

    return 'pending';
};

const stepMarkerClass = (step, index) => ({
    complete: 'border-green-600 bg-green-600 text-white',
    active: 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-200',
    pending: 'border-stone-200 bg-white text-stone-400 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-500',
}[stepState(step, index)]);

const statusBadgeClass = computed(() => {
    if (isReady.value) {
        return 'bg-green-100 text-green-700';
    }

    if (isFailed.value) {
        return 'bg-rose-100 text-rose-700';
    }

    return 'bg-blue-100 text-blue-700';
});

const formatDateTime = (value) => value ? new Date(value).toLocaleString() : 'Not set';

const stopPolling = () => {
    if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
};

const refreshStatus = () => {
    if (isTerminal.value || isRefreshing.value) {
        return;
    }

    isRefreshing.value = true;

    router.reload({
        only: ['workspace'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            isRefreshing.value = false;
            lastRefreshAt.value = new Date();
        },
    });
};

const startPolling = () => {
    if (pollTimer || isTerminal.value) {
        return;
    }

    refreshStatus();
    pollTimer = window.setInterval(refreshStatus, Number(props.poll_interval_ms || 1500));
};

const scheduleDetailsRedirect = () => {
    if (!isReady.value || redirectTimer) {
        return;
    }

    stopPolling();
    redirectTimer = window.setTimeout(() => {
        router.visit(detailsHref.value);
    }, 1200);
};

watch(
    () => [status.value, progress.value],
    () => {
        if (isReady.value) {
            scheduleDetailsRedirect();
            return;
        }

        if (isFailed.value) {
            stopPolling();
            return;
        }

        startPolling();
    },
);

onMounted(() => {
    if (isReady.value) {
        scheduleDetailsRedirect();
        return;
    }

    if (!isFailed.value) {
        startPolling();
    }
});

onBeforeUnmount(() => {
    stopPolling();

    if (redirectTimer) {
        window.clearTimeout(redirectTimer);
    }
});
</script>

<template>
    <Head :title="`${workspace.company_name} provisioning`" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">
                            Demo provisioning
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <h1 class="text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ workspace.company_name }}
                            </h1>
                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium" :class="statusBadgeClass">
                                {{ workspace.status_label }}
                            </span>
                        </div>
                        <p class="mt-2 max-w-3xl text-sm text-stone-600 dark:text-neutral-400">
                            {{ supportingText }}
                        </p>
                    </div>

                    <div v-if="isTerminal" class="flex flex-wrap gap-2">
                        <Link
                            v-if="isReady"
                            :href="detailsHref"
                            class="inline-flex items-center justify-center rounded-sm bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                        >
                            Open details
                        </Link>
                        <Link
                            :href="listHref"
                            class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            Back to demo list
                        </Link>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div>
                        <div class="flex items-start gap-4">
                            <div
                                class="mt-1 flex size-11 shrink-0 items-center justify-center rounded-full"
                                :class="isFailed ? 'bg-rose-100 text-rose-700' : (isReady ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700')"
                            >
                                <svg v-if="!isTerminal" class="size-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
                                </svg>
                                <svg v-else-if="isReady" class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m5 13 4 4L19 7" />
                                </svg>
                                <svg v-else class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18z" />
                                </svg>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h2 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ headline }}
                                        </h2>
                                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                            {{ stageLabel }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-3xl font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ progress }}%
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ isRefreshing ? 'Refreshing now' : `Updated ${lastRefreshAt.toLocaleTimeString()}` }}
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-5 h-3 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                                    <div
                                        class="h-full rounded-full transition-all duration-500"
                                        :class="isFailed ? 'bg-rose-600' : 'bg-green-600'"
                                        :style="{ width: `${progress}%` }"
                                    ></div>
                                </div>

                                <div
                                    v-if="isFailed"
                                    class="mt-5 rounded-sm border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200"
                                >
                                    <div class="font-semibold">Provisioning failed</div>
                                    <p class="mt-1">
                                        {{ workspace.provisioning_error || 'No explicit error message was captured for this failure.' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-8 grid gap-3 md:grid-cols-5">
                            <div
                                v-for="(step, index) in steps"
                                :key="step.label"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700"
                            >
                                <div class="flex items-center gap-2">
                                    <div
                                        class="flex size-7 items-center justify-center rounded-full border text-xs font-semibold"
                                        :class="stepMarkerClass(step, index)"
                                    >
                                        {{ index + 1 }}
                                    </div>
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ step.label }}
                                    </div>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                    {{ step.description }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <aside class="space-y-3 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                        <div>
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Workspace</div>
                            <div class="mt-2 text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ workspace.company_type }} / {{ workspace.company_sector || 'general' }}</div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ workspace.team_size }} seats</div>
                        </div>

                        <div class="border-t border-stone-200 pt-3 dark:border-neutral-700">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Modules</div>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="label in workspace.module_labels"
                                    :key="label"
                                    class="rounded-full bg-white px-2 py-1 text-[11px] text-stone-700 ring-1 ring-stone-200 dark:bg-neutral-900 dark:text-neutral-200 dark:ring-neutral-700"
                                >
                                    {{ label }}
                                </span>
                            </div>
                        </div>

                        <div class="border-t border-stone-200 pt-3 text-xs text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                            <div>Queued {{ formatDateTime(workspace.queued_at) }}</div>
                            <div>Started {{ formatDateTime(workspace.provisioning_started_at) }}</div>
                            <div v-if="workspace.provisioning_finished_at">Finished {{ formatDateTime(workspace.provisioning_finished_at) }}</div>
                        </div>
                    </aside>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
