<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    invoice: Object,
    company: Object,
});

const { t } = useI18n();
const invoice = computed(() => props.invoice || {});
const companyName = computed(() => props.company?.name || t('invoices.company_fallback'));
const companyLogo = computed(() => props.company?.logo_url || null);

const customer = computed(() => invoice.value.customer || null);
const work = computed(() => invoice.value.work || null);
const invoiceItems = computed(() => invoice.value.items || []);
const isTaskBased = computed(() => invoiceItems.value.length > 0);
const lineItems = computed(() => (isTaskBased.value ? invoiceItems.value : work.value?.products || []));
const payments = computed(() => invoice.value.payments || []);

const customerName = computed(() => {
    const data = customer.value;
    if (!data) {
        return t('invoices.labels.customer_fallback');
    }
    const name = data.company_name || `${data.first_name || ''} ${data.last_name || ''}`.trim();
    return name || t('invoices.labels.customer_fallback');
});

const contactName = computed(() => {
    const data = customer.value;
    if (!data) {
        return '-';
    }
    const name = `${data.first_name || ''} ${data.last_name || ''}`.trim();
    return name || data.company_name || '-';
});

const contactEmail = computed(() => customer.value?.email || '-');
const contactPhone = computed(() => customer.value?.phone || '-');

const fallbackProperty = computed(() => {
    const properties = customer.value?.properties || [];
    return properties.find((item) => item.is_default) || properties[0] || null;
});

const property = computed(() => work.value?.quote?.property || fallbackProperty.value);

const formatDate = (value) => humanizeDate(value) || '-';

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatShortDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleDateString();
};

const formatTimeRange = (start, end) => {
    const startLabel = start ? String(start).slice(0, 5) : '';
    const endLabel = end ? String(end).slice(0, 5) : '';
    if (!startLabel && !endLabel) {
        return '-';
    }
    if (!endLabel) {
        return startLabel;
    }
    return `${startLabel} - ${endLabel}`;
};

const invoiceSubtotal = computed(() => {
    if (isTaskBased.value) {
        return invoiceItems.value.reduce((sum, item) => sum + Number(item.total || 0), 0);
    }

    if (work.value?.subtotal !== undefined && work.value?.subtotal !== null) {
        return Number(work.value.subtotal || 0);
    }

    return Number(invoice.value.total || 0);
});

const lineItemColspan = computed(() => (isTaskBased.value ? 5 : 4));

const statusLabel = computed(() => {
    const status = invoice.value?.status || 'draft';
    const key = `invoices.status.${status}`;
    const translated = t(key);
    return translated === key ? status : translated;
});

const statusClass = (status) => {
    switch (status) {
        case 'draft':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-200';
        case 'sent':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400';
        case 'partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'paid':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'overdue':
        case 'void':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-200';
    }
};
</script>

<template>
    <Head :title="$t('invoices.show.title', { number: invoice.number || invoice.id })" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div class="p-5 space-y-3 flex flex-col bg-stone-100 border border-stone-100 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-800">
                <div class="flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-3">
                        <img
                            v-if="companyLogo"
                            :src="companyLogo"
                            :alt="companyName"
                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                        />
                        <div>
                            <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                {{ companyName }}
                            </p>
                            <h1 class="text-xl inline-block font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('invoices.show.invoice_for', { customer: customerName }) }}
                            </h1>
                            <p class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ work?.job_title || $t('invoices.labels.job_fallback') }}
                            </p>
                        </div>
                    </div>
                    <span class="py-1.5 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                        :class="statusClass(invoice.status)">
                        {{ statusLabel }}
                    </span>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="col-span-2 space-x-2">
                        <div class="bg-white rounded-sm border border-stone-100 p-4 mb-4 text-stone-700 dark:bg-neutral-900 dark:border-neutral-800 dark:text-neutral-100">
                            {{ work?.job_title || $t('invoices.labels.job_fallback') }}
                        </div>
                        <div class="flex flex-row space-x-6">
                            <div class="lg:col-span-3">
                                <p class="text-sm text-stone-700 dark:text-neutral-200">{{ $t('invoices.show.property_address') }}</p>
                                <div v-if="property" class="space-y-1">
                                    <div class="text-xs text-stone-600 dark:text-neutral-300">{{ property.country }}</div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-300">{{ property.street1 }}</div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-300">{{ property.state }} - {{ property.zip }}</div>
                                </div>
                                <div v-else class="text-xs text-stone-600 dark:text-neutral-400">
                                    {{ $t('invoices.show.no_property') }}
                                </div>
                            </div>
                            <div class="lg:col-span-3">
                                <p class="text-sm text-stone-700 dark:text-neutral-200">{{ $t('invoices.show.contact_details') }}</p>
                                <div class="text-xs text-stone-600 dark:text-neutral-300">
                                    {{ contactName }}
                                </div>
                                <div class="text-xs text-stone-600 dark:text-neutral-300">
                                    {{ contactEmail }}
                                </div>
                                <div class="text-xs text-stone-600 dark:text-neutral-300">
                                    {{ contactPhone }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-4 rounded-sm border border-stone-100 dark:bg-neutral-900 dark:border-neutral-800">
                        <p class="text-sm text-stone-700 dark:text-neutral-200">{{ $t('invoices.show.invoice_details') }}</p>
                        <div class="text-xs text-stone-600 dark:text-neutral-300 flex justify-between">
                            <span>{{ $t('invoices.show.invoice_label') }}:</span>
                            <span>{{ invoice.number || invoice.id }}</span>
                        </div>
                        <div class="text-xs text-stone-600 dark:text-neutral-300 flex justify-between">
                            <span>{{ $t('invoices.show.issued') }}:</span>
                            <span>{{ formatDate(invoice.created_at) }}</span>
                        </div>
                        <div class="text-xs text-stone-600 dark:text-neutral-300 flex justify-between">
                            <span>{{ $t('invoices.show.balance_due') }}:</span>
                            <span>{{ formatCurrency(invoice.balance_due) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-5 space-y-3 flex flex-col bg-white border border-stone-100 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                        <thead>
                            <tr>
                                <th class="min-w-[300px] text-left text-sm font-medium text-stone-800 dark:text-neutral-100">
                                    {{ isTaskBased ? $t('invoices.show.table.tasks') : $t('invoices.show.table.products_services') }}
                                </th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.date') }}</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.time') }}</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.assignee') }}</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.total') }}</th>
                                <th v-else class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.qty') }}</th>
                                <th v-else class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.unit_cost') }}</th>
                                <th v-else class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="item in lineItems" :key="item.id">
                                <template v-if="isTaskBased">
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ item.title }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatShortDate(item.scheduled_date) }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatTimeRange(item.start_time, item.end_time) }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ item.assignee_name || '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatCurrency(item.total) }}</td>
                                </template>
                                <template v-else>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ item.name }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ item.pivot?.quantity ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatCurrency(item.pivot?.price) }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatCurrency(item.pivot?.total) }}</td>
                                </template>
                            </tr>
                            <tr v-if="!lineItems.length">
                                <td :colspan="lineItemColspan" class="px-4 py-4 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('invoices.show.line_items_empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-stone-100 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-800">
                <div></div>
                <div class="border-l border-stone-200 rounded-sm p-4 dark:border-neutral-700">
                    <div class="py-4 grid grid-cols-2 gap-x-4">
                        <div class="col-span-1">
                            <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.summary.subtotal') }}:</p>
                        </div>
                        <div class="col-span-1 flex justify-end">
                            <p class="text-sm text-green-600 dark:text-emerald-400">
                                {{ formatCurrency(invoiceSubtotal) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                        <div class="col-span-1">
                            <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.summary.paid') }}:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(invoice.amount_paid) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                        <div class="col-span-1">
                            <p class="text-sm text-stone-800 font-bold dark:text-neutral-100">{{ $t('invoices.show.summary.total_amount') }}:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-stone-800 font-bold dark:text-neutral-100">
                                {{ formatCurrency(invoice.total) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                        <div class="col-span-1">
                            <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.summary.balance_due') }}:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(invoice.balance_due) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100 mb-3">{{ $t('invoices.show.payments.title') }}</h2>
                <div v-if="payments.length" class="space-y-2">
                    <div v-for="payment in payments" :key="payment.id"
                        class="flex items-center justify-between p-2 rounded-sm bg-stone-50 dark:bg-neutral-800">
                        <div>
                            <p class="text-sm text-stone-700 dark:text-neutral-200">
                                {{ formatCurrency(payment.amount) }} - {{ payment.method || $t('invoices.labels.method_fallback') }}
                            </p>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatDate(payment.paid_at) }}
                            </p>
                        </div>
                        <span class="text-xs text-stone-500 dark:text-neutral-400">{{ payment.status }}</span>
                    </div>
                </div>
                <p v-else class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.payments.empty') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
