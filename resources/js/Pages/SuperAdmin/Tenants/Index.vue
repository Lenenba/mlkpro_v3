<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import useDataTableFilters from '@/Composables/useDataTableFilters';

const props = defineProps({
    filters: {
        type: Object,
        required: true,
    },
    tenants: {
        type: Object,
        required: true,
    },
    plans: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
});

const showFilters = ref(false);
const { t } = useI18n();

const companyTypeOptions = computed(() => ([
    { value: 'services', label: t('super_admin.tenants.company_types.services') },
    { value: 'products', label: t('super_admin.tenants.company_types.products') },
]));

const statusOptions = computed(() => ([
    { value: 'active', label: t('super_admin.tenants.status.active') },
    { value: 'suspended', label: t('super_admin.tenants.status.suspended') },
]));

const planOptions = computed(() =>
    (props.plans || []).map((plan) => ({
        value: String(plan.key),
        label: plan.name,
    }))
);

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatDate = (value) => {
    if (! value) {
        return t('super_admin.common.not_available');
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString();
};

const form = useForm({
    search: props.filters?.search ?? '',
    company_type: props.filters?.company_type ?? '',
    status: props.filters?.status ?? '',
    plan: props.filters?.plan ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
});

const tenantRows = computed(() => props.tenants?.data || []);
const tenantLinks = computed(() => props.tenants?.links || []);
const tenantsTotal = computed(() => Number(props.tenants?.total || tenantRows.value.length || 0));
const tenantResultsLabel = computed(() => t('super_admin.tenants.filters.results', { count: tenantsTotal.value }));
const currentPerPage = computed(() => resolveDataTablePerPage(props.tenants?.per_page, props.filters?.per_page));

const { apply: applyFilters, clear: clearFilters } = useDataTableFilters(
    form,
    route('superadmin.tenants.index')
);

const statusLabel = (tenant) => {
    if (tenant.is_suspended) {
        return t('super_admin.tenants.status.suspended');
    }

    return t('super_admin.tenants.status.active');
};
</script>

<template>
    <Head :title="$t('super_admin.tenants.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.tenants.title') }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.subtitle') }}
                    </p>
                </div>
            </section>

            <div class="grid grid-cols-2 gap-2 md:grid-cols-3 md:gap-3 lg:gap-5 xl:grid-cols-5">
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.stats.total_companies') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.total) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-blue-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.stats.active') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.active) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-rose-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.stats.suspended') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.suspended) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.stats.new_30d') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.new_30d) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-sky-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.tenants.stats.onboarded') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.onboarded) }}
                    </p>
                </div>
            </div>

            <AdminDataTable
                :rows="tenantRows"
                :links="tenantLinks"
                :total="tenantsTotal"
                :result-label="tenantResultsLabel"
                :empty-description="$t('super_admin.tenants.empty')"
                container-class="border-t-4 border-t-zinc-600"
                show-per-page
                :per-page="currentPerPage"
            >
                <template #toolbar>
                    <AdminDataTableToolbar
                        :show-filters="showFilters"
                        :search-placeholder="$t('super_admin.tenants.filters.search_placeholder')"
                        :filters-label="$t('super_admin.common.filters')"
                        :clear-label="$t('super_admin.common.clear')"
                        :apply-label="$t('super_admin.common.apply_filters')"
                        @toggle-filters="showFilters = !showFilters"
                        @apply="applyFilters"
                        @clear="clearFilters"
                    >
                        <template #search="{ searchPlaceholder }">
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 start-0 z-20 flex items-center ps-3.5">
                                    <svg class="size-4 shrink-0 text-stone-500 dark:text-neutral-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input
                                    v-model="form.search"
                                    type="text"
                                    :placeholder="searchPlaceholder"
                                    class="block w-full rounded-sm border border-stone-200 bg-white py-[7px] ps-10 pe-8 text-sm text-stone-700 placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                                >
                            </div>
                        </template>

                        <template #filters>
                            <div>
                                <FloatingSelect
                                    v-model="form.company_type"
                                    :label="$t('super_admin.tenants.filters.company_type')"
                                    :options="companyTypeOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="form.status"
                                    :label="$t('super_admin.tenants.filters.status')"
                                    :options="statusOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="form.plan"
                                    :label="$t('super_admin.tenants.filters.plan')"
                                    :options="planOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                />
                            </div>
                            <div>
                                <DatePicker v-model="form.created_from" :label="$t('super_admin.tenants.filters.created_from')" />
                            </div>
                            <div>
                                <DatePicker v-model="form.created_to" :label="$t('super_admin.tenants.filters.created_to')" />
                            </div>
                        </template>
                    </AdminDataTableToolbar>
                </template>

                <template #head>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                        <th class="px-4 py-3">{{ $t('super_admin.tenants.table.company') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.tenants.table.owner') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.tenants.table.type') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.tenants.table.plan') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.tenants.table.status') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.tenants.table.created') }}</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </template>

                <template #row="{ row: tenant }">
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <div class="font-medium text-stone-800 dark:text-neutral-100">
                                {{ tenant.company_name || $t('super_admin.tenants.table.unnamed_company') }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ tenant.email }}</div>
                        </td>
                        <td class="px-4 py-3">{{ tenant.name }}</td>
                        <td class="px-4 py-3">{{ tenant.company_type || $t('super_admin.common.not_available') }}</td>
                        <td class="px-4 py-3">{{ tenant.subscription?.plan_name || $t('super_admin.common.not_available') }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs"
                                :class="tenant.is_suspended ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'"
                            >
                                {{ statusLabel(tenant) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ formatDate(tenant.created_at) }}</td>
                        <td class="px-4 py-3 text-right">
                            <AdminDataTableActions :label="$t('super_admin.common.actions')">
                                <Link
                                    :href="route('superadmin.tenants.show', tenant.id)"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    {{ $t('super_admin.common.view') }}
                                </Link>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </template>
            </AdminDataTable>
        </div>
    </AuthenticatedLayout>
</template>
