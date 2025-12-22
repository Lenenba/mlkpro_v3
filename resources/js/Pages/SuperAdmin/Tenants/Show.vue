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
        <div class="mx-auto w-full max-w-6xl space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">
                        {{ tenant.company_name || 'Tenant' }}
                    </h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                        {{ tenant.email }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link :href="route('superadmin.tenants.export', tenant.id)"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Export data
                    </Link>
                    <button v-if="canImpersonate" type="button" @click="impersonate"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-blue-600 text-white hover:bg-blue-700">
                        Impersonate
                    </button>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-3">
                <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 lg:col-span-2">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Company details</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 text-sm text-gray-700 dark:text-neutral-200">
                        <div>
                            <span class="text-xs text-gray-500 dark:text-neutral-400">Owner</span>
                            <div class="font-medium">{{ tenant.name }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 dark:text-neutral-400">Type</span>
                            <div class="font-medium">{{ tenant.company_type || 'n/a' }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 dark:text-neutral-400">Location</span>
                            <div class="font-medium">{{ tenant.company_country || 'n/a' }} {{ tenant.company_city || '' }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 dark:text-neutral-400">Created</span>
                            <div class="font-medium">{{ new Date(tenant.created_at).toLocaleDateString() }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 dark:text-neutral-400">Onboarding</span>
                            <div class="font-medium">{{ tenant.onboarding_completed_at ? 'Completed' : 'Pending' }}</div>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 dark:text-neutral-400">Status</span>
                            <div class="font-medium">{{ tenant.is_suspended ? 'Suspended' : 'Active' }}</div>
                        </div>
                    </div>

                    <div v-if="tenant.subscription" class="mt-4 rounded-sm border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <div class="font-medium">Subscription</div>
                        <div class="mt-1">Plan: {{ tenant.subscription.plan_name || tenant.subscription.stripe_price }}</div>
                        <div>Status: {{ tenant.subscription.stripe_status }}</div>
                    </div>
                </div>

                <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Usage</h2>
                    <div class="mt-3 space-y-2 text-sm text-gray-700 dark:text-neutral-200">
                        <div>Customers: <span class="font-semibold">{{ stats.customers }}</span></div>
                        <div>Quotes: <span class="font-semibold">{{ stats.quotes }}</span></div>
                        <div>Invoices: <span class="font-semibold">{{ stats.invoices }}</span></div>
                        <div>Jobs: <span class="font-semibold">{{ stats.works }}</span></div>
                        <div>Products: <span class="font-semibold">{{ stats.products }}</span></div>
                        <div>Services: <span class="font-semibold">{{ stats.services }}</span></div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Account actions</h2>
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
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                    Reset onboarding
                                </button>
                            </div>
                        </div>
                        <div v-else class="text-sm text-gray-500 dark:text-neutral-400">
                            You do not have permission to manage tenant actions.
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Feature flags</h2>
                    <form v-if="canManage" class="mt-4 space-y-2" @submit.prevent="updateFeatures">
                        <label v-for="flag in feature_flags" :key="flag.key" class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="featureForm.features[flag.key]" :value="true" />
                            <span>{{ flag.label }}</span>
                        </label>
                        <InputError class="mt-1" :message="featureForm.errors.features" />
                        <button type="submit"
                            class="mt-3 py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            Update features
                        </button>
                    </form>
                    <div v-else class="mt-4 text-sm text-gray-500 dark:text-neutral-400">
                        You do not have permission to update feature flags.
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
