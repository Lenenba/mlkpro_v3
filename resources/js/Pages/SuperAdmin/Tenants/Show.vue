<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
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
    usage_limits: {
        type: Object,
        default: () => ({ items: [], overrides: {} }),
    },
});

const page = usePage();
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));
const platformPermissions = computed(() => page.props.auth?.account?.platform?.permissions || []);
const canManage = computed(() => isSuperadmin.value || platformPermissions.value.includes('tenants.manage'));
const canImpersonate = computed(() => isSuperadmin.value || platformPermissions.value.includes('support.impersonate'));

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

const impersonate = () => {
    if (!canImpersonate.value) {
        return;
    }
    router.post(route('superadmin.tenants.impersonate', props.tenant.id));
};
</script>

<template>
    <Head :title="tenant.company_name || 'Tenant'" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ tenant.company_name || 'Tenant' }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ tenant.email }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('superadmin.tenants.export', tenant.id)"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                            Export data
                        </Link>
                        <button v-if="canImpersonate" type="button" @click="impersonate"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-blue-600 text-white hover:bg-blue-700">
                            Impersonate
                        </button>
                    </div>
                </div>
            </section>

            <div class="grid gap-3 lg:grid-cols-3">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 lg:col-span-2">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Company details</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 text-sm text-stone-700 dark:text-neutral-200">
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">Owner</span>
                            <div class="font-medium">{{ tenant.name }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">Type</span>
                            <div class="font-medium">{{ tenant.company_type || 'n/a' }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">Location</span>
                            <div class="font-medium">{{ tenant.company_country || 'n/a' }} {{ tenant.company_city || '' }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">Created</span>
                            <div class="font-medium">{{ new Date(tenant.created_at).toLocaleDateString() }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">Onboarding</span>
                            <div class="font-medium">{{ tenant.onboarding_completed_at ? 'Completed' : 'Pending' }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">Status</span>
                            <div class="font-medium">{{ tenant.is_suspended ? 'Suspended' : 'Active' }}</div>
                        </div>
                    </div>

                    <div v-if="tenant.subscription" class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <div class="font-medium">Subscription</div>
                        <div class="mt-1">Plan: {{ tenant.subscription.plan_name || tenant.subscription.price_id }}</div>
                        <div>Status: {{ tenant.subscription.status }}</div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Usage</h2>
                    <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <div>Customers: <span class="font-semibold">{{ stats.customers }}</span></div>
                        <div>Quotes: <span class="font-semibold">{{ stats.quotes }}</span></div>
                        <div>Invoices: <span class="font-semibold">{{ stats.invoices }}</span></div>
                        <div>Jobs: <span class="font-semibold">{{ stats.works }}</span></div>
                        <div>Products: <span class="font-semibold">{{ stats.products }}</span></div>
                        <div>Services: <span class="font-semibold">{{ stats.services }}</span></div>
                        <div>Tasks: <span class="font-semibold">{{ stats.tasks }}</span></div>
                        <div>Team members: <span class="font-semibold">{{ stats.team_members }}</span></div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Account actions</h2>
                    <div class="mt-4 space-y-3">
                        <div v-if="tenant.is_suspended" class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                            Suspended: {{ tenant.suspension_reason || 'No reason provided.' }}
                        </div>
                        <div v-if="canManage" class="space-y-3">
                            <div>
                                <FloatingInput v-model="suspendForm.reason" label="Suspension reason" />
                                <InputError class="mt-1" :message="suspendForm.errors.reason" />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button v-if="!tenant.is_suspended" type="button" @click="suspendTenant"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-red-600 text-white hover:bg-red-700">
                                    Suspend tenant
                                </button>
                                <button v-else type="button" @click="restoreTenant"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-emerald-600 text-white hover:bg-emerald-700">
                                    Restore tenant
                                </button>
                                <button type="button" @click="resetOnboarding"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                    Reset onboarding
                                </button>
                            </div>
                        </div>
                        <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
                            You do not have permission to manage tenant actions.
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Feature flags</h2>
                    <form v-if="canManage" class="mt-4 space-y-2" @submit.prevent="updateFeatures">
                        <label v-for="flag in feature_flags" :key="flag.key" class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="featureForm.features[flag.key]" :value="true" />
                            <span>{{ flag.label }}</span>
                        </label>
                        <InputError class="mt-1" :message="featureForm.errors.features" />
                        <button type="submit"
                            class="mt-3 py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            Update features
                        </button>
                    </form>
                    <div v-else class="mt-4 text-sm text-stone-500 dark:text-neutral-400">
                        You do not have permission to update feature flags.
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Usage vs limits</h2>
                    <div
                        class="mt-4 overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                            <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <tr>
                                    <th class="py-2">Module</th>
                                    <th class="py-2">Used</th>
                                    <th class="py-2">Limit</th>
                                    <th class="py-2">Usage</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr v-for="item in usage_limits.items" :key="item.key">
                                    <td class="py-2">{{ item.label }}</td>
                                    <td class="py-2">{{ item.used }}</td>
                                    <td class="py-2">{{ item.limit ?? 'Unlimited' }}</td>
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
                                        No usage data available.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="usage_limits.items?.some((item) => item.status !== 'ok')"
                        class="mt-3 rounded-sm border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
                        Some modules are close to or above their limits.
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Manual limit overrides</h2>
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
                            Save overrides
                        </button>
                    </form>
                    <div v-else class="mt-4 text-sm text-stone-500 dark:text-neutral-400">
                        You do not have permission to update limits.
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
