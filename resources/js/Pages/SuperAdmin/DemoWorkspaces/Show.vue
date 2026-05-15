<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    workspace: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can_view_tenant: { type: Boolean, default: false },
    can_impersonate: { type: Boolean, default: false },
});

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

const extraAccessStatusClass = (status) => ({
    active: 'bg-emerald-100 text-emerald-700',
    revoked: 'bg-rose-100 text-rose-700',
    pending: 'bg-amber-100 text-amber-700',
}[status] || 'bg-stone-100 text-stone-700');

const brandingSwatches = (profile) => [
    profile?.primary_color || '#0F766E',
    profile?.secondary_color || '#0F172A',
    profile?.accent_color || '#F59E0B',
    profile?.surface_color || '#F8FAFC',
];

const defaultLogoUrl = (companyType, companySector) => {
    if (companyType === 'products' || companySector === 'retail') {
        return '/images/presets/company-4.svg';
    }

    if (companySector === 'restaurant') {
        return '/images/presets/company-2.svg';
    }

    if (['field_services', 'professional_services'].includes(companySector)) {
        return '/images/presets/company-3.svg';
    }

    return '/images/presets/company-1.svg';
};

const resolvedWorkspaceLogoUrl = computed(() => {
    const explicitLogoUrl = String(props.workspace.branding_profile?.logo_url || '').trim();

    if (explicitLogoUrl) {
        return explicitLogoUrl;
    }

    return defaultLogoUrl(props.workspace.company_type, props.workspace.company_sector);
});

const formatDate = (value) => value ? new Date(value).toLocaleDateString() : 'Not set';
const formatDateTime = (value) => value ? new Date(value).toLocaleString() : 'Not set';
const formatNumber = (value) => new Intl.NumberFormat().format(Number(value || 0));

const selectedModules = computed(() => props.workspace.selected_modules || []);
const showFinanceSnapshot = computed(() => selectedModules.value.includes('expenses') || selectedModules.value.includes('accounting'));
const financeSnapshotCards = computed(() => {
    const summary = props.workspace.seed_summary || {};
    const cards = [];

    if (selectedModules.value.includes('expenses')) {
        cards.push(
            {
                key: 'expenses',
                label: 'Expenses seeded',
                value: summary.expenses ?? 0,
                tone: 'stone',
            },
            {
                key: 'expenses_due',
                label: 'Due now',
                value: summary.expenses_due ?? 0,
                tone: 'amber',
            },
            {
                key: 'expenses_paid',
                label: 'Paid or reimbursed',
                value: summary.expenses_paid ?? 0,
                tone: 'emerald',
            },
            {
                key: 'expense_attachments',
                label: 'Receipt files',
                value: summary.expense_attachments ?? 0,
                tone: 'blue',
            },
        );
    }

    if (selectedModules.value.includes('accounting')) {
        cards.push(
            {
                key: 'accounting_entries',
                label: 'Journal entries',
                value: summary.accounting_entries ?? 0,
                tone: 'slate',
            },
            {
                key: 'accounting_batches',
                label: 'Generated batches',
                value: summary.accounting_batches ?? 0,
                tone: 'slate',
            },
            {
                key: 'accounting_review_required_batches',
                label: 'Review required',
                value: summary.accounting_review_required_batches ?? 0,
                tone: 'amber',
            },
            {
                key: 'accounting_active_periods',
                label: 'Active periods',
                value: summary.accounting_active_periods ?? 0,
                tone: 'blue',
            },
        );
    }

    return cards;
});

const financeSnapshotCardClass = (tone) => ({
    stone: 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200',
    slate: 'border-slate-200 bg-slate-50 text-slate-800 dark:border-slate-900/60 dark:bg-slate-950/30 dark:text-slate-200',
    amber: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200',
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200',
    blue: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-200',
}[tone] || 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200');

const salesStatusOptions = computed(() =>
    (props.options?.sales_statuses || []).filter((option) => option.value !== 'all'),
);

const currentListQuery = computed(() => {
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

    return query;
});

const backToListHref = computed(() => route('superadmin.demo-workspaces.index', currentListQuery.value));

const actionPayload = () => ({
    redirect_to: 'show',
    ...(currentListQuery.value.status ? { return_status: currentListQuery.value.status } : {}),
    ...(currentListQuery.value.sales_status ? { return_sales_status: currentListQuery.value.sales_status } : {}),
    ...(currentListQuery.value.page ? { return_page: currentListQuery.value.page } : {}),
});

const expirationDate = ref(
    props.workspace.expires_at ? String(props.workspace.expires_at).slice(0, 10) : '',
);

const cloneDraft = reactive({
    company_name: `${props.workspace.company_name} Copy`,
    prospect_name: props.workspace.prospect_name || '',
    prospect_email: props.workspace.prospect_email || '',
    prospect_company: props.workspace.prospect_company || '',
    clone_data_mode: 'keep_current_profile',
    seed_profile: props.workspace.seed_profile,
    expires_at: dateFromNow(14),
});

const cloneDataModeOptions = computed(() => props.options?.clone_data_modes || []);
const cloneUsesCurrentProfile = computed(() => cloneDraft.clone_data_mode === 'keep_current_profile');
const cloneSeedProfileLabel = computed(() =>
    (props.options?.seed_profiles || []).find((profile) => profile.value === props.workspace.seed_profile)?.label
        || props.workspace.seed_profile
        || 'Current profile',
);
const isDraftWorkspace = computed(() => props.workspace.status === 'draft');
const isQueuedWorkspace = computed(() => props.workspace.status === 'queued');
const isPurgedWorkspace = computed(() => props.workspace.status === 'purged');
const canQueueProvisioning = computed(() => (isDraftWorkspace.value || isQueuedWorkspace.value) && !isPurgedWorkspace.value);
const queueProvisioningLabel = computed(() => isQueuedWorkspace.value ? 'Run provisioning now' : 'Queue provisioning');
const canManageProvisionedWorkspace = computed(() => Boolean(props.workspace.owner_user_id) && !isPurgedWorkspace.value);
const canSendAccessEmail = computed(() => (
    Boolean(props.workspace.owner_user_id)
    && Boolean(props.workspace.prospect_email)
    && !isDraftWorkspace.value
    && !isPurgedWorkspace.value
    && props.workspace.status !== 'expired'
));

const confirmationAction = ref(null);
const destructiveActionPending = ref(false);
const extraAccessActionPending = ref(null);

const showActionConfirmation = computed(() => Boolean(confirmationAction.value));

const confirmationMeta = computed(() => {
    if (!confirmationAction.value) {
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
            title: `Purge "${props.workspace.company_name}"?`,
            body: 'This will permanently delete live tenant access and demo data. The workspace will remain visible as purged for lifecycle history.',
            impact: 'Use this only when the demo is no longer needed or has reached the end of its sales lifecycle.',
            context: props.workspace.owner?.email
                ? `Tenant owner: ${props.workspace.owner.email}`
                : 'The tenant owner account will also be removed with the demo data.',
            confirmLabel: 'Purge demo',
            pendingLabel: 'Purging demo...',
            confirmClass: 'border-transparent bg-rose-600 text-white hover:bg-rose-700',
        };
    }

    const baselineContext = props.workspace.baseline_created_at
        ? `Saved baseline: ${formatDateTime(props.workspace.baseline_created_at)}.`
        : 'No explicit baseline snapshot is recorded yet; the current demo snapshot will be used as the reset reference.';
    const resetContext = props.workspace.last_reset_at
        ? ` Last reset: ${formatDateTime(props.workspace.last_reset_at)}.`
        : ' This demo has not been reset yet.';

    return {
        title: `Reset "${props.workspace.company_name}" to baseline?`,
        body: 'This will delete the current tenant data and reprovision the demo from its saved reference state.',
        impact: 'Use reset when you need to bring the demo back to a clean, story-ready environment.',
        context: `${baselineContext}${resetContext}`,
        confirmLabel: 'Reset demo',
        pendingLabel: 'Resetting demo...',
        confirmClass: 'border-transparent bg-amber-500 text-white hover:bg-amber-600',
    };
});

const openCreatePage = () => {
    router.visit(route('superadmin.demo-workspaces.create'));
};

const closeActionConfirmation = () => {
    if (destructiveActionPending.value) {
        return;
    }

    confirmationAction.value = null;
};

const openActionConfirmation = (action) => {
    confirmationAction.value = action;
};

const updateExpiration = () => {
    if (!expirationDate.value) return;

    router.patch(route('superadmin.demo-workspaces.expiration.update', props.workspace.id), {
        expires_at: expirationDate.value,
        ...actionPayload(),
    }, { preserveScroll: true });
};

const extendExpiration = (days) => {
    router.patch(route('superadmin.demo-workspaces.expiration.extend', props.workspace.id), {
        days,
        ...actionPayload(),
    }, { preserveScroll: true });
};

const updateDeliveryStatus = (sent) => {
    router.patch(route('superadmin.demo-workspaces.delivery.update', props.workspace.id), {
        sent,
        ...actionPayload(),
    }, { preserveScroll: true });
};

const sendAccessEmail = () => {
    router.post(route('superadmin.demo-workspaces.access-email.send', props.workspace.id), actionPayload(), {
        preserveScroll: true,
    });
};

const updateSalesStatus = (salesStatus) => {
    router.patch(route('superadmin.demo-workspaces.sales-status.update', props.workspace.id), {
        sales_status: salesStatus,
        ...actionPayload(),
    }, { preserveScroll: true });
};

const queueProvisioning = () => {
    router.post(route('superadmin.demo-workspaces.queue', props.workspace.id), actionPayload(), {
        preserveScroll: true,
    });
};

const runExtraAccessAction = (roleKey, action) => {
    const pendingKey = `${roleKey}:${action}`;

    extraAccessActionPending.value = pendingKey;

    router.post(route(`superadmin.demo-workspaces.extra-access.${action}`, {
        demoWorkspace: props.workspace.id,
        roleKey,
    }), actionPayload(), {
        preserveScroll: true,
        onFinish: () => {
            if (extraAccessActionPending.value === pendingKey) {
                extraAccessActionPending.value = null;
            }
        },
    });
};

const revokeExtraAccess = (roleKey) => runExtraAccessAction(roleKey, 'revoke');
const regenerateExtraAccess = (roleKey) => runExtraAccessAction(roleKey, 'regenerate');

const isExtraAccessActionPending = (roleKey, action) => extraAccessActionPending.value === `${roleKey}:${action}`;

const cloneWorkspace = () => {
    router.post(route('superadmin.demo-workspaces.clone', props.workspace.id), {
        ...cloneDraft,
        seed_profile: cloneUsesCurrentProfile.value ? props.workspace.seed_profile : cloneDraft.seed_profile,
        ...actionPayload(),
    }, { preserveScroll: true });
};

const saveBaseline = () => {
    router.put(route('superadmin.demo-workspaces.baseline.snapshot', props.workspace.id), actionPayload(), {
        preserveScroll: true,
    });
};

const resetWorkspaceToBaseline = () => {
    openActionConfirmation('reset');
};

const purgeWorkspace = () => {
    openActionConfirmation('purge');
};

const confirmDestructiveAction = () => {
    if (!confirmationAction.value || destructiveActionPending.value) {
        return;
    }

    destructiveActionPending.value = true;

    if (confirmationAction.value === 'purge') {
        router.delete(route('superadmin.demo-workspaces.destroy', props.workspace.id), {
            preserveScroll: true,
            data: {
                ...(currentListQuery.value.status ? { return_status: currentListQuery.value.status } : {}),
                ...(currentListQuery.value.sales_status ? { return_sales_status: currentListQuery.value.sales_status } : {}),
                ...(currentListQuery.value.page ? { return_page: currentListQuery.value.page } : {}),
            },
            onSuccess: closeActionConfirmation,
            onFinish: () => {
                destructiveActionPending.value = false;
            },
        });

        return;
    }

    router.post(route('superadmin.demo-workspaces.baseline.reset', props.workspace.id), actionPayload(), {
        preserveScroll: true,
        onSuccess: closeActionConfirmation,
        onFinish: () => {
            destructiveActionPending.value = false;
        },
    });
};

const copyAccessKit = async () => {
    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(props.workspace.access_kit_text);
            window.alert(`Access kit copied for "${props.workspace.company_name}".`);
            return;
        }
    } catch (error) {
        // ignore and fallback
    }

    window.prompt('Copy the access kit below:', props.workspace.access_kit_text);
};
</script>

<template>
    <Head :title="workspace.company_name" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                    <div class="space-y-3">
                        <Link :href="backToListHref" class="inline-flex items-center text-sm font-medium text-stone-600 hover:text-stone-900 dark:text-neutral-400 dark:hover:text-neutral-100">
                            <svg class="mr-2 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m15 18-6-6 6-6" />
                            </svg>
                            Back to demo list
                        </Link>

                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">
                            {{ workspace.company_type }} / {{ workspace.company_sector || 'general' }}
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ workspace.company_name }}</h1>
                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium" :class="toneClass(workspace.status_tone)">
                                {{ workspace.status_label }}
                            </span>
                            <span class="inline-flex items-center rounded-full bg-stone-100 px-3 py-1 text-xs font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                                {{ workspace.sales_status_label }}
                            </span>
                        </div>

                        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-stone-600 dark:text-neutral-400">
                            <div>Created {{ formatDate(workspace.created_at) }}</div>
                            <div>Queued {{ formatDate(workspace.queued_at) }}</div>
                            <div>Provisioned {{ formatDate(workspace.provisioned_at) }}</div>
                            <div v-if="workspace.purged_at">Purged {{ formatDate(workspace.purged_at) }}</div>
                            <div>Template {{ workspace.template?.name || 'Custom setup' }}</div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-sm bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                            @click="openCreatePage"
                        >
                            Add demo
                        </button>
                        <button
                            v-if="canQueueProvisioning"
                            type="button"
                            class="rounded-sm bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                            @click="queueProvisioning"
                        >
                            {{ queueProvisioningLabel }}
                        </button>
                        <Link
                            v-if="can_view_tenant && workspace.owner_user_id"
                            :href="route('superadmin.tenants.show', workspace.owner_user_id)"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            Open tenant
                        </Link>
                        <Link
                            v-if="can_impersonate && workspace.owner_user_id"
                            :href="route('superadmin.tenants.impersonate', workspace.owner_user_id)"
                            method="post"
                            as="button"
                            type="button"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            Impersonate
                        </Link>
                    </div>
                </div>
            </section>

            <section
                v-if="workspace.provisioning_status === 'failed' || workspace.provisioning_error"
                class="rounded-sm border border-rose-200 bg-rose-50 p-5 shadow-sm dark:border-rose-900/60 dark:bg-rose-950/30"
            >
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <div class="text-xs uppercase tracking-[0.2em] text-rose-700 dark:text-rose-300">Provisioning error</div>
                        <h2 class="mt-2 text-lg font-semibold text-rose-900 dark:text-rose-100">
                            The last provisioning attempt did not complete.
                        </h2>
                        <p class="mt-2 text-sm text-rose-800 dark:text-rose-200">
                            {{ workspace.provisioning_error || 'No explicit error message was captured for this failure.' }}
                        </p>
                    </div>

                    <div class="text-sm text-rose-700 dark:text-rose-300">
                        {{ workspace.provisioning_failed_at ? `Failed ${formatDateTime(workspace.provisioning_failed_at)}` : 'Failure timestamp not available' }}
                    </div>
                </div>
            </section>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Access</div>
                    <div class="mt-2 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ workspace.access_email || 'Pending provisioning' }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ workspace.access_password || 'No password yet' }}</div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Prospect</div>
                    <div class="mt-2 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ workspace.prospect_name || 'No prospect name' }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ workspace.prospect_email || 'No email' }}</div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Expires</div>
                    <div class="mt-2 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ formatDate(workspace.expires_at) }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ workspace.sent_at ? `Sent ${formatDate(workspace.sent_at)}` : 'Not sent yet' }}
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Provisioning</div>
                        <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ workspace.provisioning_progress }}%</div>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-stone-200 dark:bg-neutral-700">
                        <div class="h-full rounded-full bg-green-600" :style="{ width: `${workspace.provisioning_progress || 0}%` }"></div>
                    </div>
                    <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">{{ workspace.provisioning_stage || 'Ready' }}</div>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1.08fr),minmax(320px,0.92fr)]">
                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Access kit</div>
                                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                    {{ isDraftWorkspace ? 'This demo is still a draft. Queue provisioning before sending credentials.' : (isPurgedWorkspace ? 'This demo has been purged. Historical details remain, but tenant access has been removed.' : (!workspace.owner_user_id ? 'Provisioning is still running or failed before credentials were generated.' : 'Copy, send, and review the handoff for the prospect.')) }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button v-if="canManageProvisionedWorkspace" type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="copyAccessKit">
                                    Copy access kit
                                </button>
                                <button v-if="canSendAccessEmail" type="button" class="rounded-sm bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="sendAccessEmail">
                                    {{ workspace.sent_at ? 'Resend access email' : 'Send access email' }}
                                </button>
                                <button v-if="canQueueProvisioning" type="button" class="rounded-sm bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700" @click="queueProvisioning">
                                    {{ queueProvisioningLabel }}
                                </button>
                                <button v-else-if="!workspace.sent_at && canManageProvisionedWorkspace && !canSendAccessEmail" type="button" class="rounded-sm bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="updateDeliveryStatus(true)">
                                    Mark as sent
                                </button>
                                <button v-else-if="workspace.sent_at && canManageProvisionedWorkspace" type="button" class="rounded-sm border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100" @click="updateDeliveryStatus(false)">
                                    Mark as not sent
                                </button>
                            </div>
                        </div>

                        <div v-if="workspace.suggested_flow" class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Suggested testing path</div>
                            <div class="mt-2 whitespace-pre-line">{{ workspace.suggested_flow }}</div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Timeline</div>
                        <div class="mt-4 space-y-3">
                            <div v-for="event in workspace.timeline || []" :key="event.id" class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ event.label }}</div>
                                        <div class="mt-1 text-sm text-stone-600 dark:text-neutral-400">{{ event.description }}</div>
                                        <div v-if="event.actor" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ event.actor.name }}</div>
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ formatDateTime(event.created_at) }}</div>
                                </div>
                            </div>

                            <div v-if="!(workspace.timeline || []).length" class="text-sm text-stone-500 dark:text-neutral-400">
                                No timeline event recorded yet.
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Clone and reset</div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                            <FloatingInput v-model="cloneDraft.company_name" label="Cloned demo name" />
                            <FloatingInput v-model="cloneDraft.prospect_name" label="Prospect name" />
                            <FloatingInput v-model="cloneDraft.prospect_email" type="email" label="Prospect email" />
                            <FloatingSelect v-model="cloneDraft.clone_data_mode" :options="cloneDataModeOptions" label="Clone mode" option-value="value" option-label="label" />
                            <FloatingSelect v-if="!cloneUsesCurrentProfile" v-model="cloneDraft.seed_profile" :options="options.seed_profiles || []" label="Fresh seed profile" option-value="value" option-label="label" />
                            <div v-else class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                                <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Current realism profile</div>
                                <div class="mt-2 font-medium">{{ cloneSeedProfileLabel }}</div>
                                <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">The cloned demo will keep the same seed profile while generating a new tenant owner and credentials.</p>
                            </div>
                            <FloatingInput v-model="cloneDraft.expires_at" type="date" label="Expiration date" />
                        </div>

                        <p class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                            Keep current profile reuses the same realism setup. Regenerate fresh data lets you choose a seed profile for a newly generated sample dataset.
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="rounded-sm bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700" @click="cloneWorkspace">
                                {{ cloneUsesCurrentProfile ? 'Clone with current profile' : 'Clone with fresh data' }}
                            </button>
                            <button v-if="canManageProvisionedWorkspace" type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="saveBaseline">
                                Save baseline
                            </button>
                            <button v-if="canManageProvisionedWorkspace" type="button" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100" @click="resetWorkspaceToBaseline">
                                Reset to baseline
                            </button>
                        </div>
                    </section>
                </div>

                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <div v-if="resolvedWorkspaceLogoUrl" class="flex size-14 items-center justify-center overflow-hidden rounded-sm border border-stone-200 bg-stone-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                                    <img :src="resolvedWorkspaceLogoUrl" :alt="`Logo for ${workspace.branding_profile?.name || workspace.company_name}`" class="max-h-full w-auto object-contain" />
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Branding</div>
                                    <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ workspace.branding_profile?.name || workspace.company_name }}</div>
                                    <div class="text-sm text-stone-600 dark:text-neutral-400">{{ workspace.branding_profile?.tagline || 'No tagline configured' }}</div>
                                </div>
                            </div>
                            <div class="flex gap-1">
                                <span v-for="(swatch, index) in brandingSwatches(workspace.branding_profile)" :key="`${workspace.id}-${index}`" class="size-4 rounded-full ring-1 ring-stone-200 dark:ring-neutral-700" :style="{ backgroundColor: swatch }"></span>
                            </div>
                        </div>
                        <p class="mt-3 text-sm leading-6 text-stone-600 dark:text-neutral-400">{{ workspace.branding_profile?.description || 'No custom brand description saved yet.' }}</p>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Sales tracking</div>
                        <div class="mt-3">
                            <FloatingSelect :model-value="workspace.sales_status" :options="salesStatusOptions" label="Sales stage" option-value="value" option-label="label" @update:model-value="updateSalesStatus" />
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Modules and scenarios</div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span v-for="label in workspace.module_labels" :key="label" class="rounded-full bg-stone-50 px-3 py-1 text-xs text-stone-700 ring-1 ring-stone-200 dark:bg-neutral-800 dark:text-neutral-200 dark:ring-neutral-700">
                                {{ label }}
                            </span>
                            <span v-for="label in workspace.scenario_pack_labels || []" :key="`scenario-${label}`" class="rounded-full bg-blue-50 px-3 py-1 text-xs text-blue-700 ring-1 ring-blue-200">
                                {{ label }}
                            </span>
                        </div>
                    </section>

                    <section
                        v-if="showFinanceSnapshot"
                        class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Finance snapshot</div>
                        <p class="mt-2 text-sm text-stone-600 dark:text-neutral-400">
                            Quick demo QA block driven by the seeded finance summary. Use it to confirm that the workspace is ready for expense and accounting walkthroughs before opening the tenant.
                        </p>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="card in financeSnapshotCards"
                                :key="card.key"
                                class="rounded-sm border p-3"
                                :class="financeSnapshotCardClass(card.tone)"
                            >
                                <div class="text-[11px] uppercase tracking-[0.18em] opacity-80">
                                    {{ card.label }}
                                </div>
                                <div class="mt-2 text-2xl font-semibold">
                                    {{ formatNumber(card.value) }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Extra role logins</div>
                        <div class="mt-3 space-y-3">
                            <div v-for="credential in workspace.extra_access_credentials || []" :key="`${workspace.id}-${credential.role_key}`" class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ credential.role_label }}</div>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium" :class="extraAccessStatusClass(credential.status)">
                                                {{ credential.status_label }}
                                            </span>
                                        </div>
                                        <div v-if="credential.name || credential.title" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ credential.name || 'Demo role user' }}<span v-if="credential.title"> · {{ credential.title }}</span>
                                        </div>
                                        <div v-if="credential.email && credential.password" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ credential.email }} · {{ credential.password }}
                                        </div>
                                        <div v-else-if="credential.status === 'revoked'" class="mt-2 text-xs text-rose-700 dark:text-rose-300">
                                            This login is currently blocked. Regenerate it to issue a fresh password and restore access.
                                        </div>
                                        <div v-else class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                            No active credential is currently exposed for this role.
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-if="credential.is_active"
                                            type="button"
                                            class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            :disabled="Boolean(extraAccessActionPending)"
                                            @click="revokeExtraAccess(credential.role_key)"
                                        >
                                            {{ isExtraAccessActionPending(credential.role_key, 'revoke') ? 'Revoking...' : 'Revoke access' }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                            :disabled="Boolean(extraAccessActionPending)"
                                            @click="regenerateExtraAccess(credential.role_key)"
                                        >
                                            {{ isExtraAccessActionPending(credential.role_key, 'regenerate') ? 'Regenerating...' : (credential.is_active ? 'Regenerate password' : 'Restore access') }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-if="!(workspace.extra_access_credentials || []).length" class="text-sm text-stone-500 dark:text-neutral-400">
                                No secondary login exposed for this demo.
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Expiration and admin actions</div>

                        <div v-if="!isPurgedWorkspace">
                            <div class="mt-3 flex flex-col gap-2 md:flex-row md:items-center">
                                <input v-model="expirationDate" type="date" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="updateExpiration">
                                    Update expiration
                                </button>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="extendExpiration(3)">
                                    +3 days
                                </button>
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="extendExpiration(7)">
                                    +7 days
                                </button>
                                <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="extendExpiration(14)">
                                    +14 days
                                </button>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            Tenant data has already been purged for this demo. Historical metadata stays available for audit and cloning.
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" @click="openCreatePage">
                                Create another demo
                            </button>
                            <button v-if="!isPurgedWorkspace" type="button" class="rounded-sm bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700" @click="purgeWorkspace">
                                Delete all data
                            </button>
                        </div>
                    </section>
                </div>
            </div>

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
        </div>
    </AuthenticatedLayout>
</template>
