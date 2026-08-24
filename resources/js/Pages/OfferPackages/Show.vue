<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { crmButtonClass } from '@/utils/crmButtonStyles';

const props = defineProps({
    offer: { type: Object, required: true },
    kpis: { type: Object, default: () => ({}) },
    customers: { type: Array, default: () => [] },
    recentUsages: { type: Array, default: () => [] },
    sales: { type: Array, default: () => [] },
    salesMeta: { type: Object, default: () => ({}) },
    sales_meta: { type: Object, default: () => ({}) },
    tenantCurrencyCode: { type: String, default: 'CAD' },
});

const { t, locale } = useI18n();
const imageFailed = ref(false);
const isPack = computed(() => props.offer?.type === 'pack');
const salesRows = computed(() => (Array.isArray(props.sales) ? props.sales : []));
const customerPackages = computed(() => (Array.isArray(props.customers) ? props.customers : []));
const usageRows = computed(() => (Array.isArray(props.recentUsages) ? props.recentUsages : []));
const offerItems = computed(() => (Array.isArray(props.offer?.items) ? props.offer.items : []));

const finite = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};
const number = (value) => new Intl.NumberFormat(locale.value || 'fr', { maximumFractionDigits: 2 }).format(finite(value));
const money = (value, currency = props.offer?.currency_code || props.tenantCurrencyCode) => {
    const normalizedCurrency = /^[A-Z]{3}$/.test(String(currency || '').toUpperCase())
        ? String(currency).toUpperCase()
        : props.tenantCurrencyCode;

    return new Intl.NumberFormat(locale.value || 'fr', {
        style: 'currency',
        currency: normalizedCurrency,
    }).format(finite(value));
};
const formatDate = (value) => {
    if (! value) return t('offer_packages.common.unavailable');
    const safeValue = /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? `${value}T12:00:00` : value;
    const date = new Date(safeValue);
    if (Number.isNaN(date.getTime())) return t('offer_packages.common.unavailable');
    return new Intl.DateTimeFormat(locale.value || 'fr', { year: 'numeric', month: 'short', day: 'numeric' }).format(date);
};
const translatedLabel = (group, value) => {
    const normalized = String(value || 'unknown');
    const key = `offer_packages.${group}.${normalized}`;
    const label = t(key);
    return label === key ? normalized : label;
};
const typeLabel = (value) => translatedLabel('types', value);
const statusLabel = (value) => translatedLabel('statuses', value);
const unitLabel = (value) => translatedLabel('units', value);
const recurrenceLabel = (value) => translatedLabel('recurrence', value);
const paymentMethodLabel = (value) => translatedLabel('payment_methods', value);

// Entity URLs are intentionally server-owned. A numeric id alone never creates a link.
const authorizedHref = (entity) => {
    if (entity?.can_view !== true || typeof entity?.href !== 'string') return '';
    const href = entity.href.trim();
    return /^(\/|https?:\/\/)/.test(href) ? href : '';
};
const safeImage = computed(() => {
    const source = typeof props.offer?.image_path === 'string' ? props.offer.image_path.trim() : '';
    return !imageFailed.value && /^(\/|https?:\/\/)/.test(source) ? source : '';
});

const billed = computed(() => finite(props.kpis.total_billed ?? props.kpis.total_revenue));
const collected = computed(() => finite(props.kpis.total_collected));
const salesMetadata = computed(() => (Object.keys(props.sales_meta || {}).length ? props.sales_meta : props.salesMeta));
const currencyBreakdown = computed(() => (Array.isArray(props.kpis.currency_breakdown)
    ? props.kpis.currency_breakdown.filter((item) => item && typeof item.currency_code === 'string')
    : []));
const hasMixedCurrencies = computed(() => Boolean(props.kpis.has_mixed_currencies) && currencyBreakdown.value.length > 1);
const salesDisplayed = computed(() => Math.max(0, finite(salesMetadata.value?.displayed ?? salesMetadata.value?.count ?? salesRows.value.length)));
const salesTotal = computed(() => Math.max(salesDisplayed.value, finite(salesMetadata.value?.total ?? salesMetadata.value?.total_count ?? salesDisplayed.value)));
const salesCountLabel = computed(() => (salesTotal.value > salesDisplayed.value
    ? t('offer_packages.common.displayed_of_total', { displayed: number(salesDisplayed.value), total: number(salesTotal.value) })
    : t('offer_packages.common.displayed_count', { count: number(salesDisplayed.value) })));
const collectionRate = computed(() => (billed.value > 0 ? Math.min(100, (collected.value / billed.value) * 100) : 0));
const usageRate = computed(() => finite(props.kpis.usage_rate));
const healthRate = computed(() => (isPack.value ? collectionRate.value : usageRate.value));
const healthProgress = computed(() => ({ width: `${Math.max(0, Math.min(100, healthRate.value))}%` }));

const kpiCards = computed(() => (isPack.value ? [
    ['billed', t('offer_packages.kpis.total_billed'), money(billed.value), t('offer_packages.kpis.catalog_price', { amount: money(props.offer?.price) })],
    ['sold', t('offer_packages.kpis.packs_sold'), number(props.kpis.sold_count), t('offer_packages.kpis.invoice_count', { count: number(props.kpis.invoice_count) })],
    ['collected', t('offer_packages.kpis.total_collected'), money(collected.value), t('offer_packages.kpis.collection_rate', { rate: number(collectionRate.value) })],
    ['balance', t('offer_packages.kpis.balance_due'), money(props.kpis.balance_due), t('offer_packages.kpis.outstanding_count', { count: number(props.kpis.outstanding_invoice_count) })],
    ['paid', t('offer_packages.kpis.paid_invoices'), number(props.kpis.paid_invoice_count), t('offer_packages.kpis.invoice_count', { count: number(props.kpis.invoice_count) })],
    ['average', t('offer_packages.kpis.average_sale'), money(props.kpis.average_revenue), t('offer_packages.kpis.per_pack')],
] : [
    ['revenue', t('offer_packages.kpis.total_revenue'), money(props.kpis.total_revenue), t('offer_packages.kpis.sales_count', { count: number(props.kpis.sold_count) })],
    ['customers', t('offer_packages.kpis.linked_customers'), number(props.kpis.assigned_customers), t('offer_packages.kpis.active_count', { count: number(props.kpis.active_customers) })],
    ['active', t('offer_packages.kpis.active_plans'), number(props.kpis.active_count), t('offer_packages.kpis.payment_due_count', { count: number(props.kpis.payment_due_count) })],
    ['remaining', t('offer_packages.kpis.remaining_balance'), number(props.kpis.remaining_quantity), unitLabel(props.offer?.unit_type)],
    ['usage', t('offer_packages.kpis.usage'), `${number(usageRate.value)}%`, `${number(props.kpis.consumed_quantity)}/${number(props.kpis.initial_quantity)}`],
    ['recurring', t('offer_packages.kpis.recurring'), number(props.kpis.recurring_count), t('offer_packages.kpis.suspended_count', { count: number(props.kpis.suspended_count) })],
]).map(([key, label, value, helper]) => ({ key, label, value, helper })));

const statusBreakdown = computed(() => Object.entries(props.kpis.status_breakdown || {}).map(([status, count]) => ({ status, count: finite(count) })));
const badgeClass = (status) => {
    if (['active', 'paid', 'completed', 'accepted'].includes(status)) return 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-300';
    if (['payment_due', 'sent', 'pending', 'partial', 'awaiting_acceptance'].includes(status)) return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
    if (['suspended', 'expired', 'cancelled', 'canceled', 'void', 'failed', 'overdue', 'rejected'].includes(status)) return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
    if (['consumed', 'refunded'].includes(status)) return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};
const packagePercent = (item) => finite(item?.initial_quantity) > 0
    ? Math.max(0, Math.min(100, (finite(item.consumed_quantity) / finite(item.initial_quantity)) * 100))
    : 0;
const packageProgress = (item) => ({ width: `${packagePercent(item)}%` });
const contact = (customer) => [customer?.email, customer?.phone].filter(Boolean).join(' · ') || t('offer_packages.common.unavailable');
const customerName = (customer) => customer?.name || t('offer_packages.common.deleted_customer');
const invoiceName = (invoice) => invoice?.number || t('offer_packages.common.invoice');
const payments = (sale) => (Array.isArray(sale?.payments) ? sale.payments : []);
</script>

<template>
    <Head :title="offer.name" />
    <AuthenticatedLayout>
        <main class="space-y-4" data-offer-package-detail :data-offer-type="isPack ? 'pack' : 'forfait'">
            <section class="overflow-hidden rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-5 sm:grid-cols-[7rem_minmax(0,1fr)]">
                    <div class="flex size-28 items-center justify-center overflow-hidden rounded-sm bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300">
                        <img v-if="safeImage" :src="safeImage" :alt="t('offer_packages.hero.image_alt', { name: offer.name })" class="size-full object-cover" loading="lazy" decoding="async" @error="imageFailed = true">
                        <svg v-else class="size-10" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 12v8H4v-8M2 7h20v5H2zM12 7v13M7.5 7C5.5 7 4 5.8 4 4.4 4 3.1 5 2 6.4 2 8.5 2 10.2 4 12 7c1.8-3 3.5-5 5.6-5C19 2 20 3.1 20 4.4 20 5.8 18.5 7 16.5 7" /></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <Link :href="route('offer-packages.index')" class="inline-flex items-center gap-2 text-sm font-medium text-stone-500 hover:text-green-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-neutral-400 dark:hover:text-green-300">
                                    <span aria-hidden="true">←</span>{{ t('offer_packages.actions.back_to_catalog') }}
                                </Link>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <h1 class="break-words text-2xl font-semibold text-stone-900 dark:text-neutral-50">{{ offer.name }}</h1>
                                    <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-700 dark:bg-neutral-700 dark:text-neutral-300">{{ typeLabel(offer.type) }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(offer.status)">{{ statusLabel(offer.status) }}</span>
                                    <span v-if="offer.is_public" class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-500/10 dark:text-green-300">{{ t('offer_packages.hero.public') }}</span>
                                    <span v-if="offer.is_recurring" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-500/10 dark:text-sky-300">{{ recurrenceLabel(offer.recurrence_frequency) }}</span>
                                    <span v-if="offer.carry_over_unused_balance" class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-500/10 dark:text-green-300">{{ t('offer_packages.hero.carry_over') }}</span>
                                </div>
                                <p class="mt-2 max-w-3xl break-words text-sm text-stone-500 dark:text-neutral-400">{{ offer.description || t('offer_packages.hero.no_description') }}</p>
                            </div>
                            <div class="shrink-0 rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 sm:text-right dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.hero.catalog_price') }}</div>
                                <div class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-50">{{ money(offer.price, offer.currency_code) }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.hero.created_on', { date: formatDate(offer.created_at) }) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section :aria-label="t('offer_packages.kpis.section_label')" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <article v-for="card in kpiCards" :key="card.key" class="min-w-0 rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs font-medium uppercase text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                    <div class="mt-2 break-words text-2xl font-semibold text-stone-900 dark:text-neutral-50">{{ card.value }}</div>
                    <div class="mt-1 break-words text-xs text-stone-500 dark:text-neutral-400">{{ card.helper }}</div>
                </article>
            </section>

            <section
                v-if="isPack && hasMixedCurrencies"
                class="rounded-sm border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10"
                data-mixed-currency-summary
                role="note"
                aria-labelledby="offer-package-currencies-title"
            >
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-300" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 8v4m0 4h.01" /></svg>
                    <div class="min-w-0 flex-1">
                        <h2 id="offer-package-currencies-title" class="font-semibold text-amber-950 dark:text-amber-100">{{ t('offer_packages.currencies.title') }}</h2>
                        <p class="mt-1 text-sm text-amber-900 dark:text-amber-200">{{ t('offer_packages.currencies.description', { currency: kpis.currency_code || offer.currency_code }) }}</p>
                        <ul class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3" :aria-label="t('offer_packages.currencies.summary_label')">
                            <li v-for="currency in currencyBreakdown" :key="currency.currency_code" class="rounded-sm border border-amber-200 bg-white p-3 dark:border-amber-500/30 dark:bg-neutral-900">
                                <div class="flex items-center justify-between gap-3">
                                    <strong class="text-stone-900 dark:text-neutral-50">{{ currency.currency_code }}</strong>
                                    <span class="text-xs font-medium text-stone-500 dark:text-neutral-400">{{ t('offer_packages.currencies.sold', { count: number(currency.sold_count) }) }}</span>
                                </div>
                                <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
                                    <div><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.currencies.billed') }}</dt><dd class="mt-1 break-words font-semibold text-stone-900 dark:text-neutral-50">{{ money(currency.total_billed, currency.currency_code) }}</dd></div>
                                    <div><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.currencies.collected') }}</dt><dd class="mt-1 break-words font-semibold text-green-700 dark:text-green-300">{{ money(currency.total_collected, currency.currency_code) }}</dd></div>
                                    <div><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.currencies.balance') }}</dt><dd class="mt-1 break-words font-semibold text-stone-900 dark:text-neutral-50">{{ money(currency.balance_due, currency.currency_code) }}</dd></div>
                                </dl>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22.5rem]">
                <div class="min-w-0 space-y-4">
                    <section v-if="isPack" class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-pack-sales-history>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div><h2 class="font-semibold text-stone-900 dark:text-neutral-50">{{ t('offer_packages.pack_history.title') }}</h2><p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">{{ t('offer_packages.pack_history.subtitle') }}</p></div>
                            <div class="text-sm font-medium text-stone-600 dark:text-neutral-300">{{ salesCountLabel }}</div>
                        </div>

                        <div v-if="salesRows.length" class="mt-4 hidden overflow-x-auto lg:block" tabindex="0" :aria-label="t('offer_packages.pack_history.scroll_label')">
                            <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                                <caption class="sr-only">{{ t('offer_packages.pack_history.table_caption', { name: offer.name }) }}</caption>
                                <thead><tr class="text-left text-xs font-medium uppercase text-stone-500 dark:text-neutral-400"><th scope="col" class="py-2 pr-4">{{ t('offer_packages.columns.customer') }}</th><th scope="col" class="px-4 py-2">{{ t('offer_packages.columns.invoice') }}</th><th scope="col" class="px-4 py-2">{{ t('offer_packages.columns.sale') }}</th><th scope="col" class="px-4 py-2">{{ t('offer_packages.columns.payments') }}</th><th scope="col" class="py-2 pl-4 text-right">{{ t('offer_packages.columns.balance') }}</th></tr></thead>
                                <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                    <tr v-for="sale in salesRows" :key="sale.id" class="align-top text-stone-700 dark:text-neutral-200">
                                        <td class="py-4 pr-4"><Link v-if="authorizedHref(sale.customer)" :href="authorizedHref(sale.customer)" class="font-semibold text-stone-900 hover:text-green-700 focus-visible:ring-2 focus-visible:ring-green-600 dark:text-neutral-50">{{ customerName(sale.customer) }}</Link><div v-else class="font-semibold text-stone-900 dark:text-neutral-50">{{ customerName(sale.customer) }}</div><div class="mt-1 max-w-52 break-words text-xs text-stone-500 dark:text-neutral-400">{{ contact(sale.customer) }}</div></td>
                                        <td class="px-4 py-4"><Link v-if="authorizedHref(sale.invoice)" :href="authorizedHref(sale.invoice)" class="font-semibold text-green-700 focus-visible:ring-2 focus-visible:ring-green-600 dark:text-green-300">{{ invoiceName(sale.invoice) }}</Link><div v-else class="font-semibold text-stone-900 dark:text-neutral-50">{{ invoiceName(sale.invoice) }}</div><span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(sale.invoice?.status)">{{ statusLabel(sale.invoice?.status) }}</span><div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">{{ formatDate(sale.sold_at || sale.invoice?.issued_at) }}</div></td>
                                        <td class="px-4 py-4"><div class="font-semibold text-stone-900 dark:text-neutral-50">{{ money(sale.total, sale.currency_code) }}</div><div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.pack_history.quantity_price', { quantity: number(sale.quantity), price: money(sale.unit_price, sale.currency_code) }) }}</div></td>
                                        <td class="px-4 py-4"><ul v-if="payments(sale).length" class="space-y-2" :aria-label="t('offer_packages.pack_history.payment_list')"><li v-for="payment in payments(sale)" :key="payment.id" class="min-w-44 rounded-sm bg-stone-50 px-2.5 py-2 dark:bg-neutral-800"><div class="flex justify-between gap-3"><strong class="text-stone-900 dark:text-neutral-50">{{ money(payment.amount, payment.currency_code || sale.currency_code) }}</strong><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(payment.status)">{{ statusLabel(payment.status) }}</span></div><div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ paymentMethodLabel(payment.method) }} · {{ formatDate(payment.paid_at) }}</div></li></ul><span v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.pack_history.no_payment') }}</span></td>
                                        <td class="py-4 pl-4 text-right"><div class="font-semibold text-stone-900 dark:text-neutral-50">{{ money(sale.balance_due, sale.currency_code) }}</div><div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.pack_history.collected', { amount: money(sale.collected_amount, sale.currency_code) }) }}</div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="salesRows.length" class="mt-4 space-y-3 lg:hidden" data-pack-sales-mobile>
                            <article v-for="sale in salesRows" :key="`mobile-${sale.id}`" class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="flex items-start justify-between gap-3"><div class="min-w-0"><Link v-if="authorizedHref(sale.invoice)" :href="authorizedHref(sale.invoice)" class="break-words font-semibold text-green-700 focus-visible:ring-2 focus-visible:ring-green-600 dark:text-green-300">{{ invoiceName(sale.invoice) }}</Link><div v-else class="break-words font-semibold text-stone-900 dark:text-neutral-50">{{ invoiceName(sale.invoice) }}</div><div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ formatDate(sale.sold_at || sale.invoice?.issued_at) }}</div></div><span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(sale.invoice?.status)">{{ statusLabel(sale.invoice?.status) }}</span></div>
                                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.columns.customer') }}</dt><dd class="mt-1 break-words font-medium"><Link v-if="authorizedHref(sale.customer)" :href="authorizedHref(sale.customer)" class="focus-visible:ring-2 focus-visible:ring-green-600">{{ customerName(sale.customer) }}</Link><span v-else>{{ customerName(sale.customer) }}</span></dd></div><div class="text-right"><dt class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.columns.sale') }}</dt><dd class="mt-1 font-semibold">{{ money(sale.total, sale.currency_code) }}</dd></div><div><dt class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.pack_history.collected_label') }}</dt><dd class="mt-1 font-medium">{{ money(sale.collected_amount, sale.currency_code) }}</dd></div><div class="text-right"><dt class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.columns.balance') }}</dt><dd class="mt-1 font-semibold">{{ money(sale.balance_due, sale.currency_code) }}</dd></div></dl>
                                <ul v-if="payments(sale).length" class="mt-4 space-y-2" :aria-label="t('offer_packages.pack_history.payment_list')"><li v-for="payment in payments(sale)" :key="payment.id" class="rounded-sm bg-white px-3 py-2 text-sm dark:bg-neutral-900"><div class="flex justify-between gap-3"><span>{{ paymentMethodLabel(payment.method) }}</span><strong>{{ money(payment.amount, payment.currency_code || sale.currency_code) }}</strong></div><div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ formatDate(payment.paid_at) }}</div></li></ul>
                            </article>
                        </div>
                        <div v-if="!salesRows.length" class="mt-4 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-5 py-10 text-center dark:border-neutral-700 dark:bg-neutral-800" role="status"><h3 class="font-semibold text-stone-900 dark:text-neutral-50">{{ t('offer_packages.pack_history.empty_title') }}</h3><p class="mx-auto mt-1 max-w-lg text-sm text-stone-500 dark:text-neutral-400">{{ t('offer_packages.pack_history.empty_body') }}</p></div>
                    </section>

                    <template v-else>
                        <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-forfait-customer-history>
                            <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="font-semibold text-stone-900 dark:text-neutral-50">{{ t('offer_packages.forfait_history.title') }}</h2><p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">{{ t('offer_packages.forfait_history.subtitle') }}</p></div><div class="text-sm font-medium text-stone-600 dark:text-neutral-300">{{ t('offer_packages.common.displayed_count', { count: customerPackages.length }) }}</div></div>
                            <div v-if="customerPackages.length" class="mt-4 overflow-x-auto" tabindex="0" :aria-label="t('offer_packages.forfait_history.scroll_label')">
                                <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700"><caption class="sr-only">{{ t('offer_packages.forfait_history.table_caption', { name: offer.name }) }}</caption><thead><tr class="text-left text-xs font-medium uppercase text-stone-500 dark:text-neutral-400"><th scope="col" class="py-2 pr-4">{{ t('offer_packages.columns.customer') }}</th><th scope="col" class="px-4 py-2">{{ t('offer_packages.columns.status') }}</th><th scope="col" class="px-4 py-2">{{ t('offer_packages.columns.balance') }}</th><th scope="col" class="px-4 py-2">{{ t('offer_packages.columns.period') }}</th><th scope="col" class="px-4 py-2">{{ t('offer_packages.columns.invoice') }}</th><th scope="col" class="py-2 pl-4 text-right">{{ t('offer_packages.columns.revenue') }}</th></tr></thead>
                                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800"><tr v-for="item in customerPackages" :key="item.id" class="align-top text-stone-700 dark:text-neutral-200"><td class="py-3 pr-4"><Link v-if="authorizedHref(item.customer)" :href="authorizedHref(item.customer)" class="font-semibold text-stone-900 focus-visible:ring-2 focus-visible:ring-green-600 dark:text-neutral-50">{{ customerName(item.customer) }}</Link><div v-else class="font-semibold text-stone-900 dark:text-neutral-50">{{ customerName(item.customer) }}</div><div class="mt-1 max-w-52 break-words text-xs text-stone-500 dark:text-neutral-400">{{ contact(item.customer) }}</div></td><td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(item.status)">{{ statusLabel(item.status) }}</span><span v-if="item.recurrence_status" class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(item.recurrence_status)">{{ statusLabel(item.recurrence_status) }}</span></td><td class="px-4 py-3"><strong>{{ number(item.remaining_quantity) }} / {{ number(item.initial_quantity) }} {{ unitLabel(item.unit_type) }}</strong><div class="mt-2 h-1.5 w-32 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800" role="progressbar" :aria-label="t('offer_packages.forfait_history.usage_progress', { customer: customerName(item.customer) })" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="packagePercent(item)"><div class="h-full bg-green-600" :style="packageProgress(item)"></div></div><div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.forfait_history.usage_count', { count: number(item.usages_count) }) }}</div></td><td class="px-4 py-3"><div>{{ formatDate(item.starts_at) }}</div><div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.forfait_history.expires_on', { date: formatDate(item.expires_at) }) }}</div><div v-if="item.next_renewal_at" class="text-xs text-stone-500 dark:text-neutral-400">{{ t('offer_packages.forfait_history.renews_on', { date: formatDate(item.next_renewal_at) }) }}</div></td><td class="px-4 py-3"><template v-if="item.renewal_invoice || item.invoice"><Link v-if="authorizedHref(item.renewal_invoice || item.invoice)" :href="authorizedHref(item.renewal_invoice || item.invoice)" class="font-medium text-green-700 focus-visible:ring-2 focus-visible:ring-green-600 dark:text-green-300">{{ invoiceName(item.renewal_invoice || item.invoice) }}</Link><span v-else>{{ invoiceName(item.renewal_invoice || item.invoice) }}</span></template><span v-else>{{ t('offer_packages.common.unavailable') }}</span></td><td class="py-3 pl-4 text-right font-semibold text-stone-900 dark:text-neutral-50">{{ money(item.price_paid, item.currency_code) }}</td></tr></tbody>
                                </table>
                            </div>
                            <div v-if="!customerPackages.length" class="mt-4 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-5 py-10 text-center dark:border-neutral-700 dark:bg-neutral-800" role="status"><h3 class="font-semibold text-stone-900 dark:text-neutral-50">{{ t('offer_packages.forfait_history.empty_title') }}</h3><p class="mx-auto mt-1 max-w-lg text-sm text-stone-500 dark:text-neutral-400">{{ t('offer_packages.forfait_history.empty_body') }}</p></div>
                        </section>

                        <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-forfait-usage-history>
                            <h2 class="font-semibold text-stone-900 dark:text-neutral-50">{{ t('offer_packages.usages.title') }}</h2><p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">{{ t('offer_packages.usages.subtitle') }}</p>
                            <ul v-if="usageRows.length" class="mt-4 space-y-3" :aria-label="t('offer_packages.usages.list_label')"><li v-for="usage in usageRows" :key="usage.id" class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800"><div class="min-w-0"><Link v-if="authorizedHref(usage.customer)" :href="authorizedHref(usage.customer)" class="break-words font-semibold text-stone-900 focus-visible:ring-2 focus-visible:ring-green-600 dark:text-neutral-50">{{ customerName(usage.customer) }}</Link><div v-else class="break-words font-semibold text-stone-900 dark:text-neutral-50">{{ customerName(usage.customer) }}</div><div class="mt-1 break-words text-xs text-stone-500 dark:text-neutral-400">{{ usage.note || usage.source || t('offer_packages.usages.fallback_note') }}</div></div><div class="text-right"><strong class="text-stone-900 dark:text-neutral-50">-{{ number(usage.quantity) }} {{ unitLabel(offer.unit_type) }}</strong><div class="text-xs text-stone-500 dark:text-neutral-400">{{ formatDate(usage.used_at) }}</div></div></li></ul>
                            <div v-if="!usageRows.length" class="mt-4 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-5 py-8 text-center dark:border-neutral-700 dark:bg-neutral-800" role="status"><h3 class="font-semibold text-stone-900 dark:text-neutral-50">{{ t('offer_packages.usages.empty_title') }}</h3><p class="mx-auto mt-1 max-w-lg text-sm text-stone-500 dark:text-neutral-400">{{ t('offer_packages.usages.empty_body') }}</p></div>
                        </section>
                    </template>
                </div>

                <aside class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="font-semibold text-stone-900 dark:text-neutral-50">{{ isPack ? t('offer_packages.health.pack_title') : t('offer_packages.health.forfait_title') }}</h2>
                        <div class="mt-4 flex justify-between gap-3 text-sm"><span class="text-stone-500 dark:text-neutral-400">{{ isPack ? t('offer_packages.health.collection_rate') : t('offer_packages.health.usage_rate') }}</span><strong>{{ number(healthRate) }}%</strong></div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800" role="progressbar" :aria-label="isPack ? t('offer_packages.health.collection_rate') : t('offer_packages.health.usage_rate')" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="healthRate"><div class="h-full bg-green-600" :style="healthProgress"></div></div>
                        <dl v-if="statusBreakdown.length" class="mt-5 space-y-2"><div v-for="item in statusBreakdown" :key="item.status" class="flex justify-between rounded-sm bg-stone-50 px-3 py-2 text-sm dark:bg-neutral-800"><dt>{{ statusLabel(item.status) }}</dt><dd class="font-semibold">{{ number(item.count) }}</dd></div></dl><p v-else class="mt-5 text-sm text-stone-500 dark:text-neutral-400">{{ t('offer_packages.health.no_status') }}</p>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="font-semibold text-stone-900 dark:text-neutral-50">{{ t('offer_packages.configuration.title') }}</h2>
                        <dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between gap-4"><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.configuration.included_quantity') }}</dt><dd class="text-right font-medium">{{ offer.included_quantity ? `${number(offer.included_quantity)} ${unitLabel(offer.unit_type)}` : t('offer_packages.common.unavailable') }}</dd></div><div class="flex justify-between gap-4"><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.configuration.validity') }}</dt><dd class="text-right font-medium">{{ offer.validity_days ? t('offer_packages.common.days', { count: number(offer.validity_days) }) : t('offer_packages.configuration.unlimited') }}</dd></div><div v-if="!isPack" class="flex justify-between gap-4"><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.configuration.recurrence') }}</dt><dd class="text-right font-medium">{{ offer.is_recurring ? recurrenceLabel(offer.recurrence_frequency) : t('offer_packages.configuration.not_recurring') }}</dd></div><div v-if="offer.is_recurring" class="flex justify-between gap-4"><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.configuration.renewal_notice') }}</dt><dd class="text-right font-medium">{{ offer.renewal_notice_days ? t('offer_packages.common.days', { count: number(offer.renewal_notice_days) }) : t('offer_packages.common.unavailable') }}</dd></div><div v-if="offer.is_recurring" class="flex justify-between gap-4"><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.configuration.payment_grace') }}</dt><dd class="text-right font-medium">{{ offer.payment_grace_days ? t('offer_packages.common.days', { count: number(offer.payment_grace_days) }) : t('offer_packages.common.unavailable') }}</dd></div><div v-if="offer.is_recurring" class="flex justify-between gap-4"><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.configuration.payment_reminders') }}</dt><dd class="text-right font-medium">{{ (offer.payment_reminder_days || []).length ? t('offer_packages.configuration.reminder_days', { days: offer.payment_reminder_days.join(', ') }) : t('offer_packages.common.unavailable') }}</dd></div><div v-if="!isPack" class="flex justify-between gap-4"><dt class="text-stone-500 dark:text-neutral-400">{{ t('offer_packages.configuration.carry_over') }}</dt><dd class="text-right font-medium">{{ offer.carry_over_unused_balance ? t('offer_packages.configuration.carried_over') : t('offer_packages.configuration.not_carried_over') }}</dd></div></dl>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><h2 class="font-semibold text-stone-900 dark:text-neutral-50">{{ t('offer_packages.items.title') }}</h2><ul v-if="offerItems.length" class="mt-4 space-y-3"><li v-for="item in offerItems" :key="item.id" class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"><div class="break-words font-semibold text-stone-900 dark:text-neutral-50">{{ item.name_snapshot || item.product_name || t('offer_packages.items.fallback') }}</div><div class="mt-1 flex flex-wrap justify-between gap-3 text-xs text-stone-500 dark:text-neutral-400"><span>{{ number(item.quantity) }} × {{ money(item.unit_price, offer.currency_code) }}</span><span>{{ item.product_type || item.item_type_snapshot || t('offer_packages.common.unavailable') }}</span></div></li></ul><p v-else class="mt-4 text-sm text-stone-500 dark:text-neutral-400">{{ t('offer_packages.items.empty') }}</p></section>
                    <Link :href="route('offer-packages.index')" :class="[crmButtonClass('secondary', 'dialog'), 'w-full justify-center']">{{ t('offer_packages.actions.back_to_list') }}</Link>
                </aside>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
