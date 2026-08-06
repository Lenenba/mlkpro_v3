<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    invoices: {
        type: Object,
        default: () => ({ data: [], links: [] }),
    },
});

const { t } = useI18n();
const { formatCurrency } = useCurrencyFormatter();

const invoiceRows = computed(() => Array.isArray(props.invoices?.data) ? props.invoices.data : []);
const paginationLinks = computed(() => Array.isArray(props.invoices?.links) ? props.invoices.links : []);

const formatDate = (value) => humanizeDate(value) || '-';

const paymentDate = (payment) => formatDate(payment?.paid_at || payment?.created_at);

const statusLabel = (status) => {
    const key = `invoices.status.${status || 'draft'}`;
    const translated = t(key);

    return translated === key ? (status || '-') : translated;
};

const paymentStatusLabel = (status) => {
    const key = `invoices.show.payments.status.${status || 'pending'}`;
    const translated = t(key);

    return translated === key ? (status || '-') : translated;
};

const invoiceStatusClass = (status) => ({
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    partial: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
    sent: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    overdue: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    void: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
}[status] || 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300');

const paymentStatusClass = (status) => ({
    completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
    failed: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    refunded: 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300',
    reversed: 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300',
}[status] || 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300');

const paymentMethod = (payment) => payment?.method || payment?.provider || t('invoices.labels.method_fallback');

const paymentChargedTotal = (payment) => {
    const explicitTotal = Number(payment?.charged_total);
    if (Number.isFinite(explicitTotal) && explicitTotal >= 0) {
        return explicitTotal;
    }

    return Number(payment?.amount || 0) + Number(payment?.tip_amount || 0);
};
</script>

<template>
    <Head :title="$t('invoices.history.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-5xl space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('invoices.history.title') }}
                </h1>
                <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('invoices.history.subtitle') }}
                </p>
            </section>

            <section v-if="invoiceRows.length" class="space-y-4">
                <article
                    v-for="invoice in invoiceRows"
                    :key="invoice.id"
                    class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <Link
                                :href="route('portal.invoices.show', invoice.id)"
                                class="font-semibold text-stone-800 hover:text-emerald-700 hover:underline dark:text-neutral-100 dark:hover:text-emerald-300"
                            >
                                {{ invoice.number || $t('invoices.labels.invoice_number', { id: invoice.id }) }}
                            </Link>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('invoices.show.issued') }}: {{ formatDate(invoice.created_at) }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium" :class="invoiceStatusClass(invoice.status)">
                            {{ statusLabel(invoice.status) }}
                        </span>
                    </div>

                    <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <dt class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('invoices.table.total') }}</dt>
                            <dd class="mt-1 font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(invoice.total, invoice.currency_code) }}
                            </dd>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <dt class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.payments.total_paid') }}</dt>
                            <dd class="mt-1 font-semibold text-emerald-700 dark:text-emerald-300">
                                {{ formatCurrency(invoice.total_paid, invoice.currency_code) }}
                            </dd>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <dt class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('invoices.table.balance_due') }}</dt>
                            <dd class="mt-1 font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(invoice.balance_due, invoice.currency_code) }}
                            </dd>
                        </div>
                    </dl>

                    <section class="mt-5 border-t border-stone-200 pt-4 dark:border-neutral-700">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('invoices.show.payments.title') }}
                        </h2>

                        <ul v-if="invoice.payments?.length" class="mt-3 space-y-2">
                            <li
                                v-for="payment in invoice.payments"
                                :key="payment.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-sm bg-stone-50 px-3 py-2 text-sm dark:bg-neutral-800"
                            >
                                <div class="min-w-0">
                                    <div class="font-medium capitalize text-stone-800 dark:text-neutral-100">
                                        {{ paymentMethod(payment) }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ paymentDate(payment) }}
                                        <span v-if="payment.reference"> · {{ payment.reference }}</span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-3 text-right">
                                    <div>
                                        <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ formatCurrency(paymentChargedTotal(payment), payment.currency_code || invoice.currency_code) }}
                                        </div>
                                        <div v-if="Number(payment.tip_amount || 0) > 0" class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ $t('invoices.show.payments.tip') }}: {{ formatCurrency(payment.tip_amount, payment.currency_code || invoice.currency_code) }}
                                        </div>
                                    </div>
                                    <span class="rounded-full px-2 py-1 text-xs font-medium" :class="paymentStatusClass(payment.status)">
                                        {{ paymentStatusLabel(payment.status) }}
                                    </span>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('invoices.show.payments.empty') }}
                        </p>
                    </section>

                    <div class="mt-4">
                        <Link
                            :href="route('portal.invoices.show', invoice.id)"
                            class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        >
                            {{ $t('invoices.actions.view_invoice') }}
                        </Link>
                    </div>
                </article>
            </section>

            <section v-else class="rounded-sm border border-dashed border-stone-300 bg-white p-8 text-center text-sm text-stone-500 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                {{ $t('invoices.empty.invoices') }}
            </section>

            <nav v-if="paginationLinks.length > 3" class="flex flex-wrap justify-center gap-1" :aria-label="$t('invoices.pagination.of')">
                <template v-for="(link, index) in paginationLinks" :key="`${index}-${link.label}`">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="rounded-sm border px-3 py-2 text-sm"
                        :class="link.active
                            ? 'border-emerald-600 bg-emerald-600 text-white'
                            : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                    >
                        <span v-html="link.label"></span>
                    </Link>
                    <span
                        v-else
                        class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-400 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-500"
                    >
                        <span v-html="link.label"></span>
                    </span>
                </template>
            </nav>
        </div>
    </AuthenticatedLayout>
</template>
