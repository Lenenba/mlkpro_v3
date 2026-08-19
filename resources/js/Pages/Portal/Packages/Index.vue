<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    customer: { type: Object, default: () => ({}) },
    company: { type: Object, default: () => ({}) },
    packages: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const selectedPackageId = ref(props.packages?.[0]?.id ?? null);
const requestType = ref(null);
const requestForm = useForm({ note: '' });
const { formatCurrency } = useCurrencyFormatter(computed(() => props.company?.currency_code));

const packageRows = computed(() => Array.isArray(props.packages) ? props.packages : []);
const selectedPackage = computed(() => packageRows.value.find((item) => item.id === selectedPackageId.value) || packageRows.value[0] || null);

watch(
    () => props.packages,
    (packages) => {
        if (!Array.isArray(packages) || packages.length === 0) {
            selectedPackageId.value = null;
            return;
        }

        if (!packages.some((item) => item.id === selectedPackageId.value)) {
            selectedPackageId.value = packages[0].id;
        }
    }
);

const translateValue = (group, value, fallback = '-') => {
    if (!value) return fallback;
    const key = `client_packages.${group}.${value}`;
    const translated = t(key);

    return translated === key ? value : translated;
};

const formatDate = (value) => humanizeDate(value) || '-';

const formatQuantity = (value) => Number(value || 0).toLocaleString();

const balancePercent = (item) => {
    const initial = Math.max(0, Number(item?.initial_quantity || 0));
    const remaining = Math.max(0, Number(item?.remaining_quantity || 0));

    if (initial <= 0) return 0;

    return Math.min(100, Math.round((remaining / initial) * 100));
};

const statusBadgeClass = (status) => ({
    active: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    consumed: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
    expired: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    cancelled: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
}[status] || 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300');

const invoiceBadgeClass = (status) => ({
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    partial: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    sent: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    overdue: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    draft: 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300',
}[status] || 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300');

const requestLabel = computed(() => requestType.value === 'renewal'
    ? t('client_packages.requests.renewal_title')
    : t('client_packages.requests.cancellation_title'));

const lastRequest = (item, type) => item?.portal_requests?.[type] || null;

const openRequest = (type) => {
    requestType.value = type;
    requestForm.clearErrors();
    requestForm.reset();
};

const closeRequest = () => {
    requestType.value = null;
    requestForm.clearErrors();
    requestForm.reset();
};

const submitRequest = () => {
    if (!selectedPackage.value || !requestType.value) return;

    const routeName = requestType.value === 'renewal'
        ? 'portal.packages.renewal-request'
        : 'portal.packages.cancellation-request';

    requestForm.post(route(routeName, selectedPackage.value.id), {
        preserveScroll: true,
        onSuccess: () => closeRequest(),
    });
};
</script>

<template>
    <Head :title="$t('client_packages.title')" />

    <AuthenticatedLayout>
        <div class="space-y-3 package-client-enter">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_packages.title') }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('client_packages.subtitle') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-sm border border-stone-200 bg-stone-50 px-3 py-1 text-xs font-medium text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ customer?.name || '-' }}
                        </span>
                        <span class="inline-flex rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                            {{ company?.name || '-' }}
                        </span>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.stats.total') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatQuantity(stats.total) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.stats.active') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-emerald-700 dark:text-emerald-400">{{ formatQuantity(stats.active) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.stats.remaining') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatQuantity(stats.remaining_quantity) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.stats.recurring') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatQuantity(stats.recurring) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.stats.payment_due') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-amber-700 dark:text-amber-400">{{ formatQuantity(stats.payment_due) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.stats.carried_over') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-sky-700 dark:text-sky-400">{{ formatQuantity(stats.carried_over_quantity) }}</div>
                </div>
            </section>

            <section v-if="packageRows.length" class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(320px,420px),minmax(0,1fr)]">
                <aside class="space-y-3">
                    <button
                        v-for="item in packageRows"
                        :key="item.id"
                        type="button"
                        class="w-full rounded-sm border bg-white p-4 text-left shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50/40 dark:bg-neutral-900 dark:hover:border-emerald-500/40 dark:hover:bg-emerald-500/5"
                        :class="selectedPackage?.id === item.id ? 'border-emerald-500 ring-1 ring-emerald-500/20 dark:border-emerald-500' : 'border-stone-200 dark:border-neutral-700'"
                        @click="selectedPackageId = item.id"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ item.name }}</h2>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatQuantity(item.remaining_quantity) }} / {{ formatQuantity(item.initial_quantity) }}
                                    {{ translateValue('units', item.unit_type) }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-sm border px-2 py-1 text-[11px] font-medium" :class="statusBadgeClass(item.status)">
                                {{ translateValue('statuses', item.status) }}
                            </span>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-sm bg-stone-100 dark:bg-neutral-800">
                            <div class="h-full rounded-sm bg-emerald-600" :style="{ width: `${balancePercent(item)}%` }"></div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                            <span>{{ $t('client_packages.labels.expires') }}: {{ item.expires_at ? formatDate(item.expires_at) : $t('client_packages.labels.no_expiry') }}</span>
                            <span v-if="item.is_recurring" class="text-stone-300 dark:text-neutral-600">|</span>
                            <span v-if="item.is_recurring">{{ translateValue('recurrence', item.recurrence_frequency) }}</span>
                        </div>
                    </button>
                </aside>

                <section v-if="selectedPackage" class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ selectedPackage.name }}</h2>
                                <span class="rounded-sm border px-2 py-1 text-[11px] font-medium" :class="statusBadgeClass(selectedPackage.status)">
                                    {{ translateValue('statuses', selectedPackage.status) }}
                                </span>
                            </div>
                            <p v-if="selectedPackage.description" class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">{{ selectedPackage.description }}</p>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.price_paid') }}</div>
                            <div class="text-base font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(selectedPackage.price_paid, selectedPackage.currency_code) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.remaining') }}</div>
                            <div class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-400">
                                {{ formatQuantity(selectedPackage.remaining_quantity) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.consumed') }}</div>
                            <div class="mt-1 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatQuantity(selectedPackage.consumed_quantity) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.allocation') }}</div>
                            <div class="mt-1 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatQuantity(selectedPackage.period_allocation_quantity) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.carried_over') }}</div>
                            <div class="mt-1 text-xl font-semibold text-sky-700 dark:text-sky-400">
                                {{ formatQuantity(selectedPackage.carried_over_quantity) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <div class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_packages.sections.period') }}</h3>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.starts') }}</dt>
                                    <dd class="font-medium text-stone-800 dark:text-neutral-100">{{ formatDate(selectedPackage.starts_at) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.expires') }}</dt>
                                    <dd class="font-medium text-stone-800 dark:text-neutral-100">{{ selectedPackage.expires_at ? formatDate(selectedPackage.expires_at) : $t('client_packages.labels.no_expiry') }}</dd>
                                </div>
                                <div v-if="selectedPackage.is_recurring" class="flex justify-between gap-4">
                                    <dt class="text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.next_renewal') }}</dt>
                                    <dd class="font-medium text-stone-800 dark:text-neutral-100">{{ formatDate(selectedPackage.next_renewal_at) }}</dd>
                                </div>
                                <div v-if="selectedPackage.is_recurring" class="flex justify-between gap-4">
                                    <dt class="text-stone-500 dark:text-neutral-400">{{ $t('client_packages.labels.recurrence_status') }}</dt>
                                    <dd class="font-medium text-stone-800 dark:text-neutral-100">{{ translateValue('recurrence_statuses', selectedPackage.recurrence_status) }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_packages.sections.actions') }}</h3>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!selectedPackage.can_request_renewal"
                                    @click="openRequest('renewal')"
                                >
                                    {{ $t('client_packages.actions.request_renewal') }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                    :disabled="!selectedPackage.can_request_cancellation"
                                    @click="openRequest('cancellation')"
                                >
                                    {{ $t('client_packages.actions.request_cancellation') }}
                                </button>
                            </div>
                            <p v-if="lastRequest(selectedPackage, 'renewal')" class="mt-3 text-xs text-emerald-700 dark:text-emerald-300">
                                {{ $t('client_packages.requests.renewal_sent') }} {{ formatDate(lastRequest(selectedPackage, 'renewal').requested_at) }}
                            </p>
                            <p v-if="lastRequest(selectedPackage, 'cancellation')" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                {{ $t('client_packages.requests.cancellation_sent') }} {{ formatDate(lastRequest(selectedPackage, 'cancellation').requested_at) }}
                            </p>

                            <form v-if="requestType" class="mt-4 space-y-3" @submit.prevent="submitRequest">
                                <div>
                                    <label class="text-xs font-medium text-stone-600 dark:text-neutral-300">{{ requestLabel }}</label>
                                    <textarea
                                        v-model="requestForm.note"
                                        rows="3"
                                        class="mt-1 block w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100"
                                        :placeholder="$t('client_packages.requests.note_placeholder')"
                                    ></textarea>
                                    <p v-if="requestForm.errors.note" class="mt-1 text-xs text-rose-600">{{ requestForm.errors.note }}</p>
                                    <p v-if="requestForm.errors.customer_package_id" class="mt-1 text-xs text-rose-600">{{ requestForm.errors.customer_package_id }}</p>
                                </div>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200" @click="closeRequest">
                                        {{ $t('client_packages.actions.cancel') }}
                                    </button>
                                    <button type="submit" class="rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50" :disabled="requestForm.processing">
                                        {{ $t('client_packages.actions.submit_request') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <div class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_packages.sections.invoices') }}</h3>
                            <div v-if="selectedPackage.invoices?.length" class="mt-3 divide-y divide-stone-200 border border-stone-200 dark:divide-neutral-700 dark:border-neutral-700">
                                <div v-for="invoice in selectedPackage.invoices" :key="invoice.id" class="flex flex-wrap items-center justify-between gap-3 p-3">
                                    <div>
                                        <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                            {{ translateValue('invoice_types', invoice.type) }} {{ invoice.number }}
                                        </div>
                                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ formatCurrency(invoice.total, invoice.currency_code) }} - {{ formatDate(invoice.created_at) }}
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="rounded-sm px-2 py-1 text-[11px] font-medium" :class="invoiceBadgeClass(invoice.status)">
                                            {{ translateValue('invoice_statuses', invoice.status) }}
                                        </span>
                                        <Link :href="route('portal.invoices.show', invoice.id)" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                            {{ $t('client_packages.actions.view_invoice') }}
                                        </Link>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="mt-3 rounded-sm border border-dashed border-stone-300 px-4 py-6 text-center text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                                {{ $t('client_packages.empty.no_invoices') }}
                            </p>
                        </div>

                        <div class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_packages.sections.usages') }}</h3>
                            <div v-if="selectedPackage.usages?.length" class="mt-3 divide-y divide-stone-200 border border-stone-200 dark:divide-neutral-700 dark:border-neutral-700">
                                <div v-for="usage in selectedPackage.usages" :key="usage.id" class="p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                                -{{ formatQuantity(usage.quantity) }} {{ translateValue('units', selectedPackage.unit_type) }}
                                            </div>
                                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ formatDate(usage.used_at) }} - {{ translateValue('sources', usage.source) }}
                                            </div>
                                        </div>
                                        <span v-if="usage.product_name" class="rounded-sm bg-stone-100 px-2 py-1 text-[11px] text-stone-700 dark:bg-neutral-800 dark:text-neutral-300">
                                            {{ usage.product_name }}
                                        </span>
                                    </div>
                                    <p v-if="usage.note" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">{{ usage.note }}</p>
                                </div>
                            </div>
                            <p v-else class="mt-3 rounded-sm border border-dashed border-stone-300 px-4 py-6 text-center text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                                {{ $t('client_packages.empty.no_usages') }}
                            </p>
                        </div>
                    </div>
                </section>
            </section>

            <section v-else class="rounded-sm border border-dashed border-stone-300 bg-white px-4 py-12 text-center shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="text-base font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_packages.empty.title') }}</h2>
                <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">{{ $t('client_packages.empty.body') }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.package-client-enter {
    animation: packageClientFadeUp 240ms ease-out both;
}

@keyframes packageClientFadeUp {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .package-client-enter {
        animation: none;
    }
}
</style>
