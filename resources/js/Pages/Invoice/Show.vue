<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StarRating from '@/Components/UI/StarRating.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    invoice: Object,
});

const page = usePage();
const { t } = useI18n();
const companyName = computed(() => page.props.auth?.account?.company?.name || t('invoices.company_fallback'));
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);

const form = useForm({
    amount: '',
    method: '',
    reference: '',
    paid_at: '',
    notes: '',
});
const balanceDue = computed(() => {
    const value = Number(props.invoice?.balance_due || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const paymentAmount = computed(() => {
    const value = Number(form.amount || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const exceedsBalanceDue = computed(() => paymentAmount.value > balanceDue.value + 0.0001);
const remainingAfterPayment = computed(() => roundMoney(Math.max(0, balanceDue.value - paymentAmount.value)));
const isPartialPayment = computed(() => paymentAmount.value > 0 && paymentAmount.value < balanceDue.value);

const dispatchDemoEvent = (eventName) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent(eventName));
};

const submitPayment = () => {
    if (paymentAmount.value < 0.01) {
        form.setError('amount', 'Enter a valid amount.');
        return;
    }

    if (exceedsBalanceDue.value) {
        form.setError('amount', `Amount cannot exceed ${formatCurrency(balanceDue.value)}.`);
        return;
    }

    form.post(route('payment.store', props.invoice.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'method', 'reference', 'paid_at', 'notes');
            dispatchDemoEvent('demo:invoice_paid');
        },
    });
};

const customer = computed(() => props.invoice.customer || null);
const work = computed(() => props.invoice.work || null);
const invoiceItems = computed(() => props.invoice.items || []);
const isTaskBased = computed(() => invoiceItems.value.length > 0);
const lineItems = computed(() => (isTaskBased.value ? invoiceItems.value : work.value?.products || []));

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

const ratingValue = computed(() => {
    const ratings = work.value?.ratings || [];
    if (!ratings.length) {
        return null;
    }
    const sum = ratings.reduce((total, rating) => total + Number(rating.rating || 0), 0);
    return sum / ratings.length;
});

const ratingCount = computed(() => work.value?.ratings?.length || 0);

const formatDate = (value) => humanizeDate(value) || '-';

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const roundMoney = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
const setPaymentAmount = (value) => {
    const normalized = roundMoney(Math.max(0, Math.min(Number(value || 0), balanceDue.value)));
    form.amount = normalized > 0 ? normalized.toFixed(2) : '';
    if (form.errors.amount) {
        form.clearErrors('amount');
    }
};

const paymentTipAmount = (payment) => {
    const value = Number(payment?.tip_amount || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
};

const paymentChargedTotal = (payment) => {
    const fallback = Number(payment?.amount || 0) + paymentTipAmount(payment);
    const value = Number(payment?.charged_total ?? fallback);
    return Number.isFinite(value) ? value : fallback;
};

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

    return Number(props.invoice.total || 0);
});

const lineItemColspan = computed(() => (isTaskBased.value ? 5 : 4));

const statusLabel = computed(() => {
    const status = props.invoice?.status || 'draft';
    const key = `invoices.status.${status}`;
    const translated = t(key);
    return translated === key ? status : translated;
});
</script>

<template>
    <Head :title="$t('invoices.show.title', { number: invoice.number || invoice.id })" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5 rise-stagger">
            <div class="p-5 space-y-3 flex flex-col bg-stone-100 border border-stone-100 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-800">
                <div class="flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-3">
                        <img v-if="companyLogo"
                            :src="companyLogo"
                            :alt="companyName"
                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                            loading="lazy"
                            decoding="async" />
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
                    <div class="flex items-center gap-2">
                        <Link
                            :href="route('pipeline.timeline', { entityType: 'invoice', entityId: invoice.id })"
                            class="inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('invoices.show.timeline') }}
                        </Link>
                        <a
                            :href="route('invoice.pdf', invoice.id)"
                            class="inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            target="_blank"
                            rel="noopener"
                        >
                            {{ $t('invoices.show.download_pdf') }}
                        </a>
                        <span class="py-1.5 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                            :class="{
                                'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-200': invoice.status === 'draft',
                                'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400': invoice.status === 'sent',
                                'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400': invoice.status === 'partial',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400': invoice.status === 'paid',
                                'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400': invoice.status === 'overdue' || invoice.status === 'void',
                            }">
                            {{ statusLabel }}
                        </span>
                    </div>
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
                        <div class="text-xs text-stone-600 dark:text-neutral-300 flex justify-between">
                            <span>{{ $t('invoices.show.job_rating') }}:</span>
                            <span class="flex items-center gap-2">
                                <StarRating :value="ratingValue" show-value :empty-label="$t('invoices.show.no_rating')" />
                                <span v-if="ratingCount" class="text-xs text-stone-500 dark:text-neutral-400">({{ ratingCount }})</span>
                            </span>
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

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100 mb-3">{{ $t('invoices.show.payments.title') }}</h2>
                    <div v-if="invoice.payments?.length" class="space-y-2">
                        <div v-for="payment in invoice.payments" :key="payment.id"
                            class="flex items-center justify-between p-2 rounded-sm bg-stone-50 dark:bg-neutral-900">
                            <div>
                                <p class="text-sm text-stone-700 dark:text-neutral-200">
                                    {{ formatCurrency(payment.amount) }} - {{ payment.method || $t('invoices.labels.method_fallback') }}
                                </p>
                                <div class="mt-1 space-y-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                    <div>{{ $t('invoices.show.payments.subtotal') }}: {{ formatCurrency(payment.amount) }}</div>
                                    <div>{{ $t('invoices.show.payments.tip') }}: {{ formatCurrency(paymentTipAmount(payment)) }}</div>
                                    <div class="font-medium text-stone-700 dark:text-neutral-300">
                                        {{ $t('invoices.show.payments.total_paid') }}: {{ formatCurrency(paymentChargedTotal(payment)) }}
                                    </div>
                                    <div v-if="payment.tip_assignee?.name">
                                        {{ $t('invoices.show.payments.tip_assigned_to') }}: {{ payment.tip_assignee.name }}
                                    </div>
                                </div>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(payment.paid_at) }}
                                </p>
                            </div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">{{ payment.status }}</span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.payments.empty') }}</p>
                </div>

                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100 mb-3">{{ $t('invoices.show.add_payment.title') }}</h2>
                    <form @submit.prevent="submitPayment" class="space-y-3">
                        <input v-model="form.amount" type="number" min="0" step="0.01"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                            :placeholder="$t('invoices.show.add_payment.amount')">
                        <div v-if="form.errors.amount" class="text-xs text-red-600">{{ form.errors.amount }}</div>
                        <p class="text-[11px] text-stone-500 dark:text-neutral-400">
                            For partial payments, enter an amount lower than the current balance.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="setPaymentAmount(balanceDue)"
                            >
                                Pay full balance
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="setPaymentAmount(roundMoney(balanceDue * 0.5))"
                            >
                                50%
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="setPaymentAmount(roundMoney(balanceDue * 0.25))"
                            >
                                25%
                            </button>
                        </div>
                        <p class="text-[11px] text-stone-500 dark:text-neutral-400">
                            <template v-if="isPartialPayment">
                                Partial payment selected. Remaining after this payment:
                                <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ formatCurrency(remainingAfterPayment) }}</span>
                            </template>
                            <template v-else>
                                Remaining after this payment:
                                <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ formatCurrency(remainingAfterPayment) }}</span>
                            </template>
                        </p>
                        <input v-model="form.method" type="text"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                            :placeholder="$t('invoices.show.add_payment.method')">
                        <input v-model="form.reference" type="text"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                            :placeholder="$t('invoices.show.add_payment.reference')">
                        <DatePicker v-model="form.paid_at" :label="$t('invoices.show.add_payment.paid_at')" />
                        <textarea v-model="form.notes" rows="2"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                            :placeholder="$t('invoices.show.add_payment.notes')"></textarea>
                        <button type="submit" data-testid="demo-invoice-payment-submit"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('invoices.show.add_payment.submit') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
