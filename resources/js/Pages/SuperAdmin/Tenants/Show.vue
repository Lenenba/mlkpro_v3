<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
    feature_flags: {
        type: Array,
        default: () => [],
    },
    security: {
        type: Object,
        default: () => ({}),
    },
    usage_limits: {
        type: Object,
        default: () => ({ items: [], overrides: {} }),
    },
    plans: {
        type: Array,
        default: () => [],
    },
    billing: {
        type: Object,
        default: () => ({ provider: 'stripe', ready: false }),
    },
});

const page = usePage();
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));
const platformPermissions = computed(() => page.props.auth?.account?.platform?.permissions || []);
const canManage = computed(() => isSuperadmin.value || platformPermissions.value.includes('tenants.manage'));
const canImpersonate = computed(() => isSuperadmin.value || platformPermissions.value.includes('support.impersonate'));
const billingProvider = computed(() => props.billing?.provider ?? 'stripe');
const billingReady = computed(() => Boolean(props.billing?.ready));
const isStripeProvider = computed(() => billingProvider.value === 'stripe');
const planOptions = computed(() =>
    (props.plans || [])
        .filter((plan) => Boolean(plan?.price_id))
        .map((plan) => ({
            value: String(plan.price_id),
            label: plan.name,
        }))
);
const defaultPlanId = computed(() => {
    if (props.tenant?.subscription?.price_id) {
        return String(props.tenant.subscription.price_id);
    }
    return '';
});

const suspendForm = useForm({
    reason: '',
});

const featureForm = useForm({
    features: props.feature_flags.reduce((acc, flag) => {
        acc[flag.key] = flag.enabled;
        return acc;
    }, {}),
});

const limitsForm = useForm({
    limits: props.usage_limits?.items?.reduce((acc, item) => {
        acc[item.key] = item.override ?? '';
        return acc;
    }, {}) || {},
});

const securityForm = useForm({
    two_factor_exempt: Boolean(props.security?.two_factor_exempt),
});

const planForm = useForm({
    price_id: defaultPlanId.value,
    comped: props.tenant?.subscription?.is_comped ?? true,
});

const suspendTenant = () => {
    if (!canManage.value) {
        return;
    }
    suspendForm.post(route('superadmin.tenants.suspend', props.tenant.id), {
        preserveScroll: true,
    });
};

const restoreTenant = () => {
    if (!canManage.value) {
        return;
    }
    router.post(route('superadmin.tenants.restore', props.tenant.id), {}, { preserveScroll: true });
};

const resetOnboarding = () => {
    if (!canManage.value) {
        return;
    }
    router.post(route('superadmin.tenants.reset-onboarding', props.tenant.id), {}, { preserveScroll: true });
};

const updateFeatures = () => {
    if (!canManage.value) {
        return;
    }
    featureForm.put(route('superadmin.tenants.features.update', props.tenant.id), { preserveScroll: true });
};

const updateLimits = () => {
    if (!canManage.value) {
        return;
    }
    limitsForm.put(route('superadmin.tenants.limits.update', props.tenant.id), { preserveScroll: true });
};

const updatePlan = () => {
    if (!canManage.value) {
        return;
    }
    planForm.put(route('superadmin.tenants.plan.update', props.tenant.id), { preserveScroll: true });
};

const updateSecurity = () => {
    if (!canManage.value) {
        return;
    }
    securityForm.put(route('superadmin.tenants.security.update', props.tenant.id), { preserveScroll: true });
};

const impersonate = () => {
    if (!canImpersonate.value) {
        return;
    }
    router.post(route('superadmin.tenants.impersonate', props.tenant.id));
};
</script>

<template>
    <Head :title="tenant.company_name || $t('super_admin.tenants.detail.fallback_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ tenant.company_name || $t('super_admin.tenants.detail.fallback_title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ tenant.email }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('superadmin.tenants.export', tenant.id)"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                            {{ $t('super_admin.tenants.detail.export_data') }}
                        </Link>
                        <button v-if="canImpersonate" type="button" @click="impersonate"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-blue-600 text-white hover:bg-blue-700">
                            {{ $t('super_admin.tenants.detail.impersonate') }}
                        </button>
                    </div>
                </div>
            </section>

            <div class="grid gap-3 lg:grid-cols-3">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 lg:col-span-2">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.tenants.detail.company_details') }}
                    </h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 text-sm text-stone-700 dark:text-neutral-200">
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.tenants.detail.owner') }}
                            </span>
                            <div class="font-medium">{{ tenant.name }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.tenants.detail.type') }}
                            </span>
                            <div class="font-medium">{{ tenant.company_type || $t('super_admin.common.not_available') }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.tenants.detail.location') }}
                            </span>
                            <div class="font-medium">
                                {{ tenant.company_country || $t('super_admin.common.not_available') }} {{ tenant.company_city || '' }}
                            </div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.tenants.detail.created') }}
                            </span>
                            <div class="font-medium">{{ new Date(tenant.created_at).toLocaleDateString() }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.tenants.detail.onboarding') }}
                            </span>
                            <div class="font-medium">
                                {{ tenant.onboarding_completed_at ? $t('super_admin.tenants.detail.onboarding_completed') : $t('super_admin.tenants.detail.onboarding_pending') }}
                            </div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.tenants.detail.status') }}
                            </span>
                            <div class="font-medium">
                                {{ tenant.is_suspended ? $t('super_admin.tenants.status.suspended') : $t('super_admin.tenants.status.active') }}
                            </div>
                        </div>
                    </div>

                    <div v-if="tenant.subscription" class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <div class="font-medium">{{ $t('super_admin.tenants.detail.subscription') }}</div>
                        <div class="mt-1">
                            {{ $t('super_admin.tenants.detail.plan_label') }}: {{ tenant.subscription.plan_name || tenant.subscription.price_id }}
                        </div>
                        <div>
                            {{ $t('super_admin.tenants.detail.subscription_status') }}: {{ tenant.subscription.status }}
                        </div>
                        <div v-if="tenant.subscription.is_comped" class="mt-1 text-xs font-semibold text-emerald-600">
                            {{ $t('super_admin.tenants.detail.comped_badge') }}
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.tenants.detail.usage') }}
                    </h2>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div>{{ $t('super_admin.tenants.detail.usage_customers') }}: <span class="font-semibold">{{ stats.customers }}</span></div>
                            <div>{{ $t('super_admin.tenants.detail.usage_quotes') }}: <span class="font-semibold">{{ stats.quotes }}</span></div>
                            <div>{{ $t('super_admin.tenants.detail.usage_plan_scan_quotes') }}: <span class="font-semibold">{{ stats.plan_scan_quotes }}</span></div>
                            <div>{{ $t('super_admin.tenants.detail.usage_invoices') }}: <span class="font-semibold">{{ stats.invoices }}</span></div>
                            <div>{{ $t('super_admin.tenants.detail.usage_jobs') }}: <span class="font-semibold">{{ stats.works }}</span></div>
                        <div>{{ $t('super_admin.tenants.detail.usage_products') }}: <span class="font-semibold">{{ stats.products }}</span></div>
                        <div>{{ $t('super_admin.tenants.detail.usage_services') }}: <span class="font-semibold">{{ stats.services }}</span></div>
                        <div>{{ $t('super_admin.tenants.detail.usage_tasks') }}: <span class="font-semibold">{{ stats.tasks }}</span></div>
                        <div>{{ $t('super_admin.tenants.detail.usage_team_members') }}: <span class="font-semibold">{{ stats.team_members }}</span></div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.tenants.detail.account_actions') }}
                    </h2>
                    <div class="mt-4 space-y-3">
                        <div v-if="tenant.is_suspended" class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                            {{ $t('super_admin.tenants.detail.suspended_label') }}:
                            {{ tenant.suspension_reason || $t('super_admin.tenants.detail.suspended_reason_empty') }}
                        </div>
                        <div v-if="canManage" class="space-y-3">
                            <div>
                                <FloatingInput v-model="suspendForm.reason" :label="$t('super_admin.tenants.detail.suspension_reason')" />
                                <InputError class="mt-1" :message="suspendForm.errors.reason" />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button v-if="!tenant.is_suspended" type="button" @click="suspendTenant"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-red-600 text-white hover:bg-red-700">
                                    {{ $t('super_admin.tenants.detail.suspend_tenant') }}
                                </button>
                                <button v-else type="button" @click="restoreTenant"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-emerald-600 text-white hover:bg-emerald-700">
                                    {{ $t('super_admin.tenants.detail.restore_tenant') }}
                                </button>
                                <button type="button" @click="resetOnboarding"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                    {{ $t('super_admin.tenants.detail.reset_onboarding') }}
                                </button>
                            </div>
                        </div>
                        <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.tenants.detail.no_manage_permission') }}
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.tenants.detail.security_overrides') }}
                    </h2>
                    <div v-if="!canManage" class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.detail.no_manage_permission') }}
                    </div>
                    <form v-else class="mt-3 space-y-3" @submit.prevent="updateSecurity">
                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="securityForm.two_factor_exempt" :value="true" />
                            <span>{{ $t('super_admin.tenants.detail.security_disable_2fa') }}</span>
                        </label>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.tenants.detail.security_disable_2fa_help') }}
                        </p>
                        <button type="submit"
                            class="mt-1 py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            {{ $t('super_admin.tenants.detail.security_save') }}
                        </button>
                    </form>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.tenants.detail.feature_flags') }}
                    </h2>
                    <form v-if="canManage" class="mt-4 space-y-2" @submit.prevent="updateFeatures">
                        <label v-for="flag in feature_flags" :key="flag.key" class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="featureForm.features[flag.key]" :value="true" />
                            <span>{{ flag.label }}</span>
                        </label>
                        <InputError class="mt-1" :message="featureForm.errors.features" />
                        <button type="submit"
                            class="mt-3 py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            {{ $t('super_admin.tenants.detail.update_features') }}
                        </button>
                    </form>
                    <div v-else class="mt-4 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.detail.no_feature_permission') }}
                    </div>
                </div>
            </div>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.tenants.detail.plan_management') }}
                </h2>
                <div v-if="!canManage" class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('super_admin.tenants.detail.no_manage_permission') }}
                </div>
                <div v-else-if="!isStripeProvider || !billingReady" class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('super_admin.tenants.detail.plan_unavailable') }}
                </div>
                <div v-else-if="!planOptions.length" class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('super_admin.tenants.detail.plan_no_plans') }}
                </div>
                <form v-else class="mt-3 space-y-3" @submit.prevent="updatePlan">
                    <div class="grid gap-3 md:grid-cols-2">
                        <FloatingSelect
                            v-model="planForm.price_id"
                            :label="$t('super_admin.tenants.detail.plan_select')"
                            :options="planOptions"
                            required
                        />
                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="planForm.comped" />
                            <span>{{ $t('super_admin.tenants.detail.plan_comped_label') }}</span>
                        </label>
                    </div>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.detail.plan_comped_help') }}
                    </p>
                    <InputError class="mt-1" :message="planForm.errors.price_id" />
                    <InputError class="mt-1" :message="planForm.errors.comped" />
                    <button type="submit"
                        class="mt-2 py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-60"
                        :disabled="planForm.processing">
                        {{ $t('super_admin.tenants.detail.plan_update') }}
                    </button>
                </form>
            </section>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.tenants.detail.usage_vs_limits') }}
                    </h2>
                    <div
                        class="mt-4 overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                            <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <tr>
                                    <th class="py-2">{{ $t('super_admin.tenants.detail.usage_table.module') }}</th>
                                    <th class="py-2">{{ $t('super_admin.tenants.detail.usage_table.used') }}</th>
                                    <th class="py-2">{{ $t('super_admin.tenants.detail.usage_table.limit') }}</th>
                                    <th class="py-2">{{ $t('super_admin.tenants.detail.usage_table.usage') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr v-for="item in usage_limits.items" :key="item.key">
                                    <td class="py-2">{{ item.label }}</td>
                                    <td class="py-2">{{ item.used }}</td>
                                    <td class="py-2">{{ item.limit ?? $t('super_admin.common.unlimited') }}</td>
                                    <td class="py-2">
                                        <span v-if="item.percent !== null"
                                            :class="item.status === 'over'
                                                ? 'text-red-600'
                                                : item.status === 'warning'
                                                    ? 'text-amber-600'
                                                    : 'text-emerald-600'">
                                            {{ item.percent }}%
                                        </span>
                                        <span v-else class="text-stone-400">--</span>
                                    </td>
                                </tr>
                                <tr v-if="!usage_limits.items?.length">
                                    <td colspan="4" class="py-3 text-center text-sm text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.tenants.detail.usage_empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="usage_limits.items?.some((item) => item.status !== 'ok')"
                        class="mt-3 rounded-sm border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
                        {{ $t('super_admin.tenants.detail.usage_warning') }}
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.tenants.detail.manual_overrides') }}
                    </h2>
                    <form v-if="canManage" class="mt-4 space-y-3" @submit.prevent="updateLimits">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div v-for="item in usage_limits.items" :key="item.key">
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ item.label }}</label>
                                <input v-model="limitsForm.limits[item.key]" type="number" min="0"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="limitsForm.errors[`limits.${item.key}`]" />
                            </div>
                        </div>
                        <button type="submit"
                            class="mt-2 py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            {{ $t('super_admin.tenants.detail.save_overrides') }}
                        </button>
                    </form>
                    <div v-else class="mt-4 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.detail.no_limits_permission') }}
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
