<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    workspaces: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    can_view_tenant: { type: Boolean, default: false },
    can_impersonate: { type: Boolean, default: false },
});

const formatNumber = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
const formatDate = (value) => value ? new Date(value).toLocaleDateString() : 'Not set';
const formatDateTime = (value) => value ? new Date(value).toLocaleString() : 'Not set';
const truncateText = (value, limit = 120) => {
    const text = String(value || '').replace(/\s+/g, ' ').trim();

    if (text.length <= limit) {
        return text;
    }

    return `${text.slice(0, Math.max(0, limit - 1))}…`;
};

const dateFromNow = (days) => {
    const date = new Date();
    date.setHours(12, 0, 0, 0);
    date.setDate(date.getDate() + Number(days || 0));

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const toneClass = (tone) => ({
    emerald: 'bg-emerald-100 text-emerald-700',
    blue: 'bg-blue-100 text-blue-700',
    amber: 'bg-amber-100 text-amber-700',
    rose: 'bg-rose-100 text-rose-700',
}[tone] || 'bg-stone-100 text-stone-700');

const metaBadgeClass = (tone) => ({
    blue: 'bg-blue-100 text-blue-700',
    amber: 'bg-amber-100 text-amber-700',
    emerald: 'bg-emerald-100 text-emerald-700',
    rose: 'bg-rose-100 text-rose-700',
}[tone] || 'bg-stone-100 text-stone-700');

const deliveryBadgeClass = (sentAt) => (
    sentAt
        ? 'bg-blue-100 text-blue-700'
        : 'bg-stone-100 text-stone-700'
);

const filterClass = (value) => (
    props.filters?.status === value
        ? 'border-transparent bg-green-600 text-white'
        : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200'
);

const currentListQuery = computed(() => {
    const query = {};

    if (props.filters?.status && props.filters.status !== 'all') {
        query.status = props.filters.status;
    }

    if (props.filters?.sales_status && props.filters.sales_status !== 'all') {
        query.sales_status = props.filters.sales_status;
    }

    if (Number(props.workspaces?.current_page || 1) > 1) {
        query.page = props.workspaces.current_page;
    }

    return query;
});

const actionPayload = () => ({
    ...(currentListQuery.value.status ? { return_status: currentListQuery.value.status } : {}),
    ...(currentListQuery.value.sales_status ? { return_sales_status: currentListQuery.value.sales_status } : {}),
    ...(currentListQuery.value.page ? { return_page: currentListQuery.value.page } : {}),
});

const openCreatePage = () => {
    router.visit(route('superadmin.demo-workspaces.create'));
};

const detailsRoute = (workspaceId) => route('superadmin.demo-workspaces.show', {
    demoWorkspace: workspaceId,
    ...currentListQuery.value,
});

const openMenuWorkspaceId = ref(null);
const actionButtonRefs = ref({});
const menuRef = ref(null);
const menuStyle = ref({});
const pendingWorkspaceId = ref(null);
const confirmationAction = ref(null);
const confirmationWorkspace = ref(null);
let listenersBound = false;

const activeMenuWorkspace = computed(() =>
    (props.workspaces?.data || []).find((workspace) => workspace.id === openMenuWorkspaceId.value) || null,
);

const setActionButtonRef = (workspaceId, element) => {
    if (element) {
        actionButtonRefs.value[workspaceId] = element;
        return;
    }

    delete actionButtonRefs.value[workspaceId];
};

const isWorkspaceActionPending = (workspaceId) => pendingWorkspaceId.value === workspaceId;

const showActionConfirmation = computed(() => Boolean(confirmationAction.value && confirmationWorkspace.value));

const destructiveActionPending = computed(() => {
    const workspace = confirmationWorkspace.value;

    return workspace ? isWorkspaceActionPending(workspace.id) : false;
});

const confirmationMeta = computed(() => {
    const workspace = confirmationWorkspace.value;

    if (!workspace || !confirmationAction.value) {
        return {
            title: '',
            body: '',
            impact: '',
            context: '',
            confirmLabel: '',
            pendingLabel: 'Processing...',
            confirmClass: '',
        };
    }

    if (confirmationAction.value === 'purge') {
        return {
            title: `Purge "${workspace.company_name}"?`,
            body: 'This will permanently delete live tenant access and demo data. The workspace will remain visible as purged for lifecycle history.',
            impact: 'Use this only when the demo has expired or is no longer needed for sales follow-up.',
            context: workspace.owner?.email ? `Tenant owner: ${workspace.owner.email}` : 'The tenant owner account will also be removed with the demo data.',
            confirmLabel: 'Purge demo',
            pendingLabel: 'Purging demo...',
            confirmClass: 'border-transparent bg-rose-600 text-white hover:bg-rose-700',
        };
    }

    const baselineContext = workspace.baseline_created_at
        ? `Saved baseline: ${formatDateTime(workspace.baseline_created_at)}.`
        : 'No explicit baseline snapshot is recorded yet; the current demo snapshot will be used as the reset reference.';
    const resetContext = workspace.last_reset_at
        ? ` Last reset: ${formatDateTime(workspace.last_reset_at)}.`
        : ' This demo has not been reset yet.';

    return {
        title: `Reset "${workspace.company_name}" to baseline?`,
        body: 'This will delete the current tenant data and reprovision the demo from its saved reference state.',
        impact: 'Use reset when the prospect has explored too far and you need a clean storytelling environment again.',
        context: `${baselineContext}${resetContext}`,
        confirmLabel: 'Reset demo',
        pendingLabel: 'Resetting demo...',
        confirmClass: 'border-transparent bg-amber-500 text-white hover:bg-amber-600',
    };
});

const finishWorkspaceAction = () => {
    pendingWorkspaceId.value = null;
};

const startWorkspaceAction = (workspaceId) => {
    pendingWorkspaceId.value = workspaceId;
    closeActionMenu();
};

const closeActionConfirmation = () => {
    if (destructiveActionPending.value) {
        return;
    }

    confirmationAction.value = null;
    confirmationWorkspace.value = null;
};

const openActionConfirmation = (workspace, action) => {
    closeActionMenu();
    confirmationAction.value = action;
    confirmationWorkspace.value = workspace;
};

const visitOptions = {
    preserveScroll: true,
    onFinish: finishWorkspaceAction,
};

const extendExpiration = (workspace, days) => {
    startWorkspaceAction(workspace.id);

    router.patch(route('superadmin.demo-workspaces.expiration.extend', workspace.id), {
        days,
        ...actionPayload(),
    }, visitOptions);
};

const queueProvisioning = (workspace) => {
    startWorkspaceAction(workspace.id);

    router.post(route('superadmin.demo-workspaces.queue', workspace.id), actionPayload(), visitOptions);
};

const updateDeliveryStatus = (workspace, sent) => {
    startWorkspaceAction(workspace.id);

    router.patch(route('superadmin.demo-workspaces.delivery.update', workspace.id), {
        sent,
        ...actionPayload(),
    }, visitOptions);
};

const canSendAccessEmail = (workspace) => Boolean(
    workspace?.owner_user_id
    && workspace?.prospect_email
    && !['draft', 'purged', 'expired'].includes(workspace?.status),
);

const sendAccessEmail = (workspace) => {
    startWorkspaceAction(workspace.id);

    router.post(route('superadmin.demo-workspaces.access-email.send', workspace.id), actionPayload(), visitOptions);
};

const cloneWorkspace = (workspace) => {
    startWorkspaceAction(workspace.id);

    router.post(route('superadmin.demo-workspaces.clone', workspace.id), {
        company_name: `${workspace.company_name} Copy`,
        prospect_name: workspace.prospect_name || '',
        prospect_email: workspace.prospect_email || '',
        prospect_company: workspace.prospect_company || '',
        clone_data_mode: 'keep_current_profile',
        seed_profile: workspace.seed_profile,
        expires_at: dateFromNow(14),
        ...actionPayload(),
    }, visitOptions);
};

const executeResetWorkspaceToBaseline = (workspace) => {
    startWorkspaceAction(workspace.id);

    router.post(route('superadmin.demo-workspaces.baseline.reset', workspace.id), actionPayload(), {
        preserveScroll: true,
        onSuccess: closeActionConfirmation,
        onFinish: finishWorkspaceAction,
    });
};

const executePurgeWorkspace = (workspace) => {
    startWorkspaceAction(workspace.id);

    router.delete(route('superadmin.demo-workspaces.destroy', workspace.id), {
        preserveScroll: true,
        data: actionPayload(),
        onSuccess: closeActionConfirmation,
        onFinish: finishWorkspaceAction,
    });
};

const resetWorkspaceToBaseline = (workspace) => {
    openActionConfirmation(workspace, 'reset');
};

const purgeWorkspace = (workspace) => {
    openActionConfirmation(workspace, 'purge');
};

const confirmDestructiveAction = () => {
    const workspace = confirmationWorkspace.value;

    if (!workspace || destructiveActionPending.value) {
        return;
    }

    if (confirmationAction.value === 'purge') {
        executePurgeWorkspace(workspace);
        return;
    }

    executeResetWorkspaceToBaseline(workspace);
};

const updateMenuPosition = () => {
    const button = actionButtonRefs.value[openMenuWorkspaceId.value];

    if (!button || !menuRef.value) {
        return;
    }

    const rect = button.getBoundingClientRect();
    const menuRect = menuRef.value.getBoundingClientRect();
    const padding = 12;
    let left = rect.right - menuRect.width;

    if (left < padding) {
        left = padding;
    }

    if (left + menuRect.width > window.innerWidth - padding) {
        left = Math.max(padding, window.innerWidth - menuRect.width - padding);
    }

    let top = rect.bottom + 8;
    const maxTop = window.innerHeight - menuRect.height - padding;

    if (top > maxTop) {
        top = Math.max(padding, rect.top - menuRect.height - 8);
    }

    menuStyle.value = {
        left: `${left}px`,
        top: `${top}px`,
    };
};

const removeListeners = () => {
    if (!listenersBound) {
        return;
    }

    window.removeEventListener('resize', updateMenuPosition);
    window.removeEventListener('scroll', updateMenuPosition, true);
    document.removeEventListener('click', handleOutsideClick, true);
    document.removeEventListener('keydown', handleEscape);
    listenersBound = false;
};

const closeActionMenu = () => {
    openMenuWorkspaceId.value = null;
    removeListeners();
};

const handleOutsideClick = (event) => {
    if (!openMenuWorkspaceId.value) {
        return;
    }

    const target = event.target;
    const button = actionButtonRefs.value[openMenuWorkspaceId.value];

    if (button?.contains(target) || menuRef.value?.contains(target)) {
        return;
    }

    closeActionMenu();
};

const handleEscape = (event) => {
    if (event.key === 'Escape') {
        closeActionMenu();
    }
};

const addListeners = () => {
    if (listenersBound) {
        return;
    }

    window.addEventListener('resize', updateMenuPosition);
    window.addEventListener('scroll', updateMenuPosition, true);
    document.addEventListener('click', handleOutsideClick, true);
    document.addEventListener('keydown', handleEscape);
    listenersBound = true;
};

const toggleActionMenu = (workspaceId) => {
    if (openMenuWorkspaceId.value === workspaceId) {
        closeActionMenu();
        return;
    }

    openMenuWorkspaceId.value = workspaceId;

    nextTick(() => {
        updateMenuPosition();
        addListeners();
    });
};

onBeforeUnmount(() => {
    removeListeners();
});
</script>

<template>
    <Head title="Demo Workspaces" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Demo Workspaces</h1>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            Browse demo tenants in a compact table, then open a full page for the operational details.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                        @click="openCreatePage"
                    >
                        Add demo
                    </button>
                </div>
            </section>

            <div class="grid grid-cols-2 gap-2 md:grid-cols-5 md:gap-3">
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Active demos</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.active) }}</p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-blue-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Sent</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.sent) }}</p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Expiring soon</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.expiring_soon) }}</p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-rose-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Expired</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.expired) }}</p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-stone-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Total</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.total) }}</p>
                </div>
            </div>

            <section class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 p-5 dark:border-neutral-700">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="option in filters.options || []"
                                :key="option.value"
                                :href="route('superadmin.demo-workspaces.index', {
                                    ...(option.value === 'all' ? {} : { status: option.value }),
                                    ...(filters.sales_status && filters.sales_status !== 'all' ? { sales_status: filters.sales_status } : {}),
                                })"
                                preserve-scroll
                                class="rounded-sm border px-3 py-2 text-xs font-medium"
                                :class="filterClass(option.value)"
                            >
                                {{ option.label }}
                            </Link>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="option in filters.sales_options || []"
                                :key="`sales-${option.value}`"
                                :href="route('superadmin.demo-workspaces.index', {
                                    ...(filters.status && filters.status !== 'all' ? { status: filters.status } : {}),
                                    ...(option.value === 'all' ? {} : { sales_status: option.value }),
                                })"
                                preserve-scroll
                                class="rounded-sm border px-3 py-2 text-xs font-medium"
                                :class="filters.sales_status === option.value ? 'border-transparent bg-blue-600 text-white' : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200'"
                            >
                                {{ option.label }}
                            </Link>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto p-5">
                    <table class="min-w-full divide-y divide-stone-200 text-left text-sm text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">Demo tenant</th>
                                <th class="px-4 py-3">Prospect</th>
                                <th class="px-4 py-3">Stack</th>
                                <th class="px-4 py-3">Lifecycle</th>
                                <th class="px-4 py-3">Sales</th>
                                <th class="px-4 py-3">Expires</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="workspace in workspaces.data" :key="workspace.id" class="align-top">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">{{ workspace.company_name }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                        {{ workspace.company_type }} / {{ workspace.company_sector || 'general' }}
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-if="workspace.template"
                                            class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium"
                                            :class="metaBadgeClass('blue')"
                                        >
                                            Template: {{ workspace.template.name }}
                                        </span>
                                        <span
                                            v-if="workspace.cloned_from"
                                            class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium"
                                            :class="metaBadgeClass('amber')"
                                        >
                                            Clone of {{ workspace.cloned_from.company_name }}
                                        </span>
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">{{ workspace.prospect_name || 'No prospect' }}</div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ workspace.prospect_email || 'No email' }}</div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="text-sm text-stone-800 dark:text-neutral-100">{{ workspace.seed_profile }} / {{ workspace.team_size }} seats</div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ workspace.module_labels.length }} modules / {{ workspace.scenario_pack_labels?.length || 0 }} scenarios
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium" :class="toneClass(workspace.status_tone)">
                                        {{ workspace.status_label }}
                                    </span>
                                    <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ workspace.provisioning_progress }}% / {{ workspace.provisioning_stage || (workspace.status === 'draft' ? 'Draft saved' : 'Ready') }}
                                    </div>
                                    <div
                                        v-if="workspace.provisioning_status === 'failed' && workspace.provisioning_error"
                                        class="mt-2 max-w-xs rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200"
                                    >
                                        {{ truncateText(workspace.provisioning_error, 140) }}
                                    </div>
                                    <div
                                        v-if="workspace.provisioning_status === 'failed' && workspace.provisioning_failed_at"
                                        class="mt-1 text-[11px] text-rose-600 dark:text-rose-300"
                                    >
                                        Failed {{ formatDateTime(workspace.provisioning_failed_at) }}
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="text-sm text-stone-800 dark:text-neutral-100">{{ workspace.sales_status_label }}</div>
                                    <div class="mt-2">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium" :class="deliveryBadgeClass(workspace.sent_at)">
                                            {{ workspace.sent_at ? 'Sent' : 'Not sent' }}
                                        </span>
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ workspace.sent_at ? formatDateTime(workspace.sent_at) : 'Access kit pending' }}
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="text-sm text-stone-800 dark:text-neutral-100">{{ formatDate(workspace.expires_at) }}</div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ workspace.status === 'purged' && workspace.purged_at ? `Purged ${formatDate(workspace.purged_at)}` : formatDate(workspace.created_at) }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <button
                                        :ref="(element) => setActionButtonRef(workspace.id, element)"
                                        type="button"
                                        class="inline-flex size-8 items-center justify-center rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                        :aria-expanded="openMenuWorkspaceId === workspace.id ? 'true' : 'false'"
                                        :disabled="isWorkspaceActionPending(workspace.id)"
                                        aria-label="Open actions menu"
                                        @click="toggleActionMenu(workspace.id)"
                                    >
                                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>

                            <tr v-if="!workspaces.data.length">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-stone-500 dark:text-neutral-400">
                                    No demo workspace matches the current filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Teleport to="body">
                    <div
                        v-if="activeMenuWorkspace"
                        ref="menuRef"
                        class="fixed z-[90] w-64 rounded-sm border border-stone-200 bg-white p-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                        :style="menuStyle"
                        role="menu"
                        aria-orientation="vertical"
                    >
                        <div class="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                            Navigate
                        </div>
                        <Link
                            :href="detailsRoute(activeMenuWorkspace.id)"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            @click="closeActionMenu"
                        >
                            View details
                        </Link>
                        <Link
                            v-if="can_view_tenant && activeMenuWorkspace.owner_user_id"
                            :href="route('superadmin.tenants.show', activeMenuWorkspace.owner_user_id)"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            @click="closeActionMenu"
                        >
                            Open tenant
                        </Link>
                        <Link
                            v-if="can_impersonate && activeMenuWorkspace.owner_user_id"
                            :href="route('superadmin.tenants.impersonate', activeMenuWorkspace.owner_user_id)"
                            method="post"
                            as="button"
                            type="button"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            @click="closeActionMenu"
                        >
                            Impersonate
                        </Link>
                        <template v-if="activeMenuWorkspace.status !== 'purged'">
                            <div class="my-1 h-px bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                Quick extend
                            </div>
                            <button
                                type="button"
                                class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                role="menuitem"
                                @click="extendExpiration(activeMenuWorkspace, 3)"
                            >
                                Extend +3 days
                            </button>
                            <button
                                type="button"
                                class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                role="menuitem"
                                @click="extendExpiration(activeMenuWorkspace, 7)"
                            >
                                Extend +7 days
                            </button>
                            <button
                                type="button"
                                class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                role="menuitem"
                                @click="extendExpiration(activeMenuWorkspace, 14)"
                            >
                                Extend +14 days
                            </button>
                        </template>
                        <div class="my-1 h-px bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                            Workspace
                        </div>
                        <button
                            v-if="canSendAccessEmail(activeMenuWorkspace)"
                            type="button"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-blue-700 hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-950/30"
                            role="menuitem"
                            @click="sendAccessEmail(activeMenuWorkspace)"
                        >
                            {{ activeMenuWorkspace.sent_at ? 'Resend access email' : 'Send access email' }}
                        </button>
                        <button
                            v-if="activeMenuWorkspace.status === 'draft'"
                            type="button"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-green-700 hover:bg-green-50 dark:text-green-300 dark:hover:bg-green-950/30"
                            role="menuitem"
                            @click="queueProvisioning(activeMenuWorkspace)"
                        >
                            Queue provisioning
                        </button>
                        <button
                            v-if="activeMenuWorkspace.sent_at && !['draft', 'purged'].includes(activeMenuWorkspace.status) && activeMenuWorkspace.owner_user_id"
                            type="button"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            role="menuitem"
                            @click="updateDeliveryStatus(activeMenuWorkspace, false)"
                        >
                            Mark access as not sent
                        </button>
                        <button
                            v-else-if="!canSendAccessEmail(activeMenuWorkspace) && !['draft', 'purged'].includes(activeMenuWorkspace.status) && activeMenuWorkspace.owner_user_id"
                            type="button"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            role="menuitem"
                            @click="updateDeliveryStatus(activeMenuWorkspace, true)"
                        >
                            Mark access as sent
                        </button>
                        <button
                            type="button"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            role="menuitem"
                            @click="cloneWorkspace(activeMenuWorkspace)"
                        >
                            Clone demo
                        </button>
                        <button
                            v-if="!['draft', 'purged'].includes(activeMenuWorkspace.status) && activeMenuWorkspace.owner_user_id"
                            type="button"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            role="menuitem"
                            @click="resetWorkspaceToBaseline(activeMenuWorkspace)"
                        >
                            Reset to baseline
                        </button>
                        <button
                            v-if="activeMenuWorkspace.status !== 'purged'"
                            type="button"
                            class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-rose-600 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-950/40"
                            role="menuitem"
                            @click="purgeWorkspace(activeMenuWorkspace)"
                        >
                            Purge demo
                        </button>
                    </div>
                </Teleport>

                <Modal :show="showActionConfirmation" :closeable="!destructiveActionPending" max-width="lg" @close="closeActionConfirmation">
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-1 text-[11px] font-medium text-rose-700">
                                    Destructive action
                                </div>
                                <h2 class="mt-3 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ confirmationMeta.title }}
                                </h2>
                                <p class="mt-2 text-sm text-stone-600 dark:text-neutral-400">
                                    {{ confirmationMeta.body }}
                                </p>
                            </div>

                            <button
                                type="button"
                                class="rounded-sm px-2 py-1 text-sm text-stone-500 hover:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800"
                                :disabled="destructiveActionPending"
                                @click="closeActionConfirmation"
                            >
                                Close
                            </button>
                        </div>

                        <div class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/80">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                Impact
                            </div>
                            <p class="mt-2 text-sm text-stone-700 dark:text-neutral-200">
                                {{ confirmationMeta.impact }}
                            </p>
                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                {{ confirmationMeta.context }}
                            </p>
                        </div>

                        <div class="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                :disabled="destructiveActionPending"
                                @click="closeActionConfirmation"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border px-3 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                                :class="confirmationMeta.confirmClass"
                                :disabled="destructiveActionPending"
                                @click="confirmDestructiveAction"
                            >
                                {{ destructiveActionPending ? confirmationMeta.pendingLabel : confirmationMeta.confirmLabel }}
                            </button>
                        </div>
                    </div>
                </Modal>

                <div v-if="workspaces.links?.length" class="flex flex-wrap items-center gap-2 border-t border-stone-200 px-5 py-4 text-sm dark:border-neutral-700">
                    <template v-for="link in workspaces.links" :key="link.url || link.label">
                        <span v-if="!link.url" v-html="link.label" class="rounded-sm border border-stone-200 px-2 py-1 text-stone-400 dark:border-neutral-700"></span>
                        <Link
                            v-else
                            :href="link.url"
                            v-html="link.label"
                            preserve-scroll
                            class="rounded-sm border border-stone-200 px-2 py-1 dark:border-neutral-700"
                            :class="link.active ? 'border-transparent bg-green-600 text-white' : 'text-stone-700 hover:bg-stone-50 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                        />
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
