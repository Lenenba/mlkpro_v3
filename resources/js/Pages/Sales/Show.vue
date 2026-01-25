<script setup>
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    sale: {
        type: Object,
        required: true,
    },
    stripe: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const page = usePage();
const companyName = computed(() => page.props.auth?.account?.company?.name || t('sales.show.company_fallback'));
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);
const createdBy = computed(() => props.sale?.created_by || null);
const sellerName = computed(() => createdBy.value?.name || page.props.auth?.user?.name || t('sales.show.seller_fallback'));
const sellerEmail = computed(() => createdBy.value?.email || page.props.auth?.user?.email || null);
const sellerPhone = computed(() => createdBy.value?.phone_number || page.props.auth?.user?.phone_number || null);

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const statusLabels = computed(() => ({
    draft: t('sales.status.draft'),
    pending: t('sales.status.pending'),
    paid: t('sales.status.paid'),
    canceled: t('sales.status.canceled'),
}));

const paymentStatusLabels = computed(() => ({
    unpaid: t('sales.payment.unpaid'),
    deposit_required: t('sales.payment.deposit_required'),
    partial: t('sales.payment.partial'),
    paid: t('sales.payment.paid'),
    canceled: t('sales.status.canceled'),
    pending: t('sales.status.pending'),
}));

const statusClasses = {
    draft: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    canceled: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200',
};
const paymentStatusClasses = {
    unpaid: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    deposit_required: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    partial: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    canceled: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200',
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
};

const customerLabel = (customer) => {
    if (customer?.company_name) {
        return customer.company_name;
    }
    const name = [customer?.first_name, customer?.last_name].filter(Boolean).join(' ');
    return name || t('sales.labels.customer');
};

const canEdit = computed(() => ['draft', 'pending'].includes(props.sale?.status));

const lineCount = computed(() => props.sale?.items?.length ?? 0);
const totalQty = computed(() =>
    (props.sale?.items ?? []).reduce((sum, item) => sum + Number(item.quantity || 0), 0)
);

const productImage = (item) => item?.product?.image_url || item?.product?.image || null;
const productFallback = (item) => {
    const label = `${item?.product?.name || item?.description || t('sales.show.product_initial')}`.trim();
    if (!label) {
        return t('sales.show.product_initial');
    }
    return label.slice(0, 2).toUpperCase();
};

const companyInitials = computed(() => {
    const name = companyName.value || '';
    const parts = name.split(' ').filter(Boolean).slice(0, 2);
    if (!parts.length) {
        return t('sales.show.company_initials_fallback');
    }
    return parts.map((part) => part[0]).join('').toUpperCase();
});

const formatDate = (value) => {
    if (!value) {
        return '-';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    return date.toLocaleDateString();
};

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    return date.toLocaleString();
};

const handlePrint = () => {
    window.print();
};

const startStripePayment = () => {
    if (!canStripePay.value || stripeProcessing.value) {
        return;
    }
    stripeError.value = '';
    stripeProcessing.value = true;
    router.post(route('sales.stripe', props.sale.id), {}, {
        preserveScroll: true,
        onError: (errors) => {
            stripeError.value = errors.status || errors.message || t('sales.errors.stripe_start');
        },
        onFinish: () => {
            stripeProcessing.value = false;
        },
    });
};

const fulfillmentLabel = computed(() => {
    if (props.sale?.fulfillment_method === 'delivery') {
        return t('sales.fulfillment.method.delivery');
    }
    if (props.sale?.fulfillment_method === 'pickup') {
        return t('sales.fulfillment.method.pickup');
    }
    return t('sales.fulfillment.method.order');
});

const fulfillmentStatusLabels = computed(() => ({
    pending: t('sales.fulfillment.pending'),
    preparing: t('sales.fulfillment.preparing'),
    out_for_delivery: t('sales.fulfillment.out_for_delivery'),
    ready_for_pickup: t('sales.fulfillment.ready_for_pickup'),
    completed: t('sales.fulfillment.completed'),
    confirmed: t('sales.fulfillment.confirmed'),
}));

const fulfillmentStatusClasses = {
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    preparing: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-200',
    out_for_delivery: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    ready_for_pickup: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-200',
    completed: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    confirmed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
};

const orderStatusLabel = computed(() => {
    if (props.sale?.fulfillment_status) {
        return fulfillmentStatusLabels.value[props.sale.fulfillment_status] || props.sale.fulfillment_status;
    }
    return statusLabels.value[props.sale?.status] || props.sale?.status || '';
});
const orderStatusClass = computed(() => {
    if (props.sale?.fulfillment_status) {
        return fulfillmentStatusClasses[props.sale.fulfillment_status] || statusClasses.draft;
    }
    return statusClasses[props.sale?.status] || statusClasses.draft;
});
const paymentStatusKey = computed(() => props.sale?.payment_status || props.sale?.status || '');
const paymentStatusLabel = computed(() =>
    paymentStatusLabels.value[paymentStatusKey.value] || paymentStatusKey.value || ''
);
const paymentStatusClass = computed(() =>
    paymentStatusClasses[paymentStatusKey.value] || statusClasses.draft
);
const stripeEnabled = computed(() => Boolean(props.stripe?.enabled));
const stripeProcessing = ref(false);
const stripeError = ref('');
const canStripePay = computed(() => {
    if (!stripeEnabled.value) {
        return false;
    }
    if (!['draft', 'pending'].includes(props.sale?.status)) {
        return false;
    }
    if (['paid', 'canceled'].includes(props.sale?.payment_status)) {
        return false;
    }
    return Number(props.sale?.balance_due || 0) > 0;
});
const stripeButtonLabel = computed(() =>
    stripeProcessing.value ? t('sales.actions.pay_with_stripe_loading') : t('sales.actions.pay_with_stripe')
);

const pickupCode = computed(() => props.sale?.pickup_code || null);
const pickupQrUrl = computed(() => {
    if (!pickupCode.value) {
        return null;
    }
    return `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(pickupCode.value)}`;
});
const showPickupQr = computed(() =>
    props.sale?.fulfillment_method === 'pickup'
    && props.sale?.fulfillment_status === 'ready_for_pickup'
    && pickupQrUrl.value
);
const pickupConfirmedBy = computed(() => props.sale?.pickup_confirmed_by || null);
const canConfirmPickup = computed(() =>
    props.sale?.fulfillment_method === 'pickup'
    && props.sale?.fulfillment_status === 'ready_for_pickup'
    && !props.sale?.pickup_confirmed_at
);

const showPaymentPanel = computed(() => props.sale?.source === 'portal');
const amountPaid = computed(() => Number(props.sale?.amount_paid || 0));
const balanceDue = computed(() => Number(props.sale?.balance_due || 0));
const depositAmount = computed(() => Number(props.sale?.deposit_amount || 0));
const depositDue = computed(() => Math.max(0, depositAmount.value - amountPaid.value));
const canRecordPayment = computed(() => balanceDue.value > 0);
const canDownloadReceipt = computed(() => amountPaid.value > 0);

const paymentMethodLabel = (method) => {
    if (method === 'cash') {
        return t('sales.payments.cash');
    }
    if (method === 'card') {
        return t('sales.payments.card');
    }
    return method || '-';
};

const paymentTimeline = computed(() => {
    const list = Array.isArray(props.sale?.payments) ? props.sale.payments : [];
    const sorted = [...list]
        .filter((payment) => !payment.status || payment.status === 'completed')
        .sort((a, b) => new Date(a.paid_at || 0) - new Date(b.paid_at || 0));
    let running = 0;
    return sorted.map((payment) => {
        const amount = Number(payment.amount || 0);
        const previous = running;
        running += amount;
        let labelKey = 'payment';
        if (depositAmount.value > 0) {
            if (previous < depositAmount.value && running <= depositAmount.value) {
                labelKey = 'deposit';
            } else if (previous < depositAmount.value && running > depositAmount.value) {
                labelKey = 'deposit_balance';
            } else {
                labelKey = 'balance';
            }
        }
        return {
            ...payment,
            timeline_label: t(`sales.payments.timeline.${labelKey}`),
        };
    });
});

const paymentForm = useForm({
    amount: '',
    method: 'cash',
});

const suggestedAmount = computed(() => {
    if (!showPaymentPanel.value) {
        return 0;
    }
    if (depositDue.value > 0) {
        return depositDue.value;
    }
    return balanceDue.value;
});

watch(
    () => suggestedAmount.value,
    (value) => {
        if (!paymentForm.amount) {
            paymentForm.amount = value ? value.toFixed(2) : '';
        }
    },
    { immediate: true }
);

const submitPayment = () => {
    if (!showPaymentPanel.value || !props.sale?.id) {
        return;
    }
    paymentForm.post(route('sales.payments.store', props.sale.id), {
        preserveScroll: true,
        onSuccess: () => {
            paymentForm.reset('amount');
        },
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('sales.show.meta_title')" />

        <div class="space-y-4">
            <div class="print-card rounded-sm border border-stone-200 bg-stone-50 p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="h-12 w-12 overflow-hidden rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-800">
                            <img
                                v-if="companyLogo"
                                :src="companyLogo"
                                :alt="companyName"
                                class="h-full w-full object-cover"
                            >
                            <div v-else class="flex h-full w-full items-center justify-center text-xs font-semibold text-stone-500 dark:text-neutral-400">
                                {{ companyInitials }}
                            </div>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ companyName }}
                            </p>
                            <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('sales.show.invoice_number', { number: sale.number || $t('sales.table.sale_label', { id: sale.id }) }) }}
                            </h1>
                            <p class="text-sm text-stone-600 dark:text-neutral-400">
                                {{ sale.customer ? customerLabel(sale.customer) : $t('sales.labels.customer_anonymous') }}
                            </p>
                        </div>
                    </div>
                    <div class="space-y-1 text-sm text-stone-600 dark:text-neutral-400">
                        <div class="flex items-center justify-end gap-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="orderStatusClass"
                            >
                                {{ $t('sales.show.order_label', { status: orderStatusLabel }) }}
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="paymentStatusClass"
                            >
                                {{ $t('sales.show.payment_label', { status: paymentStatusLabel }) }}
                            </span>
                            <span>{{ $t('sales.show.created_on', { date: formatDate(sale.created_at) }) }}</span>
                        </div>
                        <div v-if="sale.paid_at" class="text-right text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('sales.show.paid_on', { date: formatDate(sale.paid_at) }) }}
                        </div>
                        <div class="text-right">
                            <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.seller_label') }}
                            </div>
                            <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">{{ sellerName }}</div>
                            <div v-if="sellerEmail" class="text-xs text-stone-500 dark:text-neutral-400">{{ sellerEmail }}</div>
                            <div v-if="sellerPhone" class="text-xs text-stone-500 dark:text-neutral-400">{{ sellerPhone }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="no-print flex flex-wrap items-center justify-end gap-2">
                <Link
                    v-if="canEdit"
                    :href="route('sales.edit', sale.id)"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ $t('sales.actions.edit') }}
                </Link>
                <button
                    v-if="canStripePay"
                    type="button"
                    class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                    :disabled="stripeProcessing"
                    @click="startStripePayment"
                >
                    {{ stripeButtonLabel }}
                </button>
                <Link
                    v-if="canDownloadReceipt"
                    :href="route('sales.receipt', sale.id)"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ $t('sales.actions.receipt') }}
                </Link>
                <Link
                    v-if="canConfirmPickup"
                    :href="route('sales.pickup.confirm', sale.id)"
                    method="post"
                    as="button"
                    class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                >
                    {{ $t('sales.show.confirm_pickup') }}
                </Link>
                <button
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    @click="handlePrint"
                >
                    {{ $t('sales.actions.print') }}
                </button>
                <Link
                    :href="route('sales.index')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ $t('sales.actions.back_to_sales') }}
                </Link>
            </div>
            <div v-if="stripeError" class="no-print text-right text-xs text-red-600 dark:text-red-300">
                {{ stripeError }}
            </div>

            <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <div class="print-card rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-stone-200 px-4 py-3 text-sm font-semibold text-stone-800 dark:border-neutral-700 dark:text-neutral-100">
                        {{ $t('sales.show.products_title') }}
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-stone-50 text-xs uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3 text-left">{{ $t('sales.show.table.product') }}</th>
                                    <th class="px-4 py-3 text-left">{{ $t('sales.show.table.sku') }}</th>
                                    <th class="px-4 py-3 text-left">{{ $t('sales.show.table.unit') }}</th>
                                    <th class="px-4 py-3 text-right">{{ $t('sales.show.table.quantity') }}</th>
                                    <th class="px-4 py-3 text-right">{{ $t('sales.show.table.price') }}</th>
                                    <th class="px-4 py-3 text-right">{{ $t('sales.show.table.total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-800">
                                <tr v-for="item in sale.items" :key="item.id">
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="h-10 w-10 overflow-hidden rounded-sm bg-stone-100 text-[10px] font-semibold text-stone-400 dark:bg-neutral-800 dark:text-neutral-500"
                                            >
                                                <img
                                                    v-if="productImage(item)"
                                                    :src="productImage(item)"
                                                    :alt="item.product?.name || $t('sales.show.product_alt')"
                                                    class="h-full w-full object-cover"
                                                >
                                                <div v-else class="flex h-full w-full items-center justify-center">
                                                    {{ productFallback(item) }}
                                                </div>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                                    {{ item.product?.name || item.description }}
                                                </div>
                                                <div
                                                    v-if="item.description && item.description !== item.product?.name"
                                                    class="text-xs text-stone-500 dark:text-neutral-400"
                                                >
                                                    {{ item.description }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-stone-500 dark:text-neutral-400">
                                        {{ item.product?.sku || '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-stone-500 dark:text-neutral-400">
                                        {{ item.product?.unit || '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-stone-700 dark:text-neutral-200">
                                        {{ item.quantity }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-stone-700 dark:text-neutral-200">
                                        {{ formatCurrency(item.price) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(item.total) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.form.customer_label') }}
                            </span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ sale.customer ? customerLabel(sale.customer) : $t('sales.labels.customer_anonymous') }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-1 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="sale.customer?.email">{{ sale.customer.email }}</div>
                            <div v-if="sale.customer?.phone">{{ sale.customer.phone }}</div>
                            <div v-if="!sale.customer">{{ $t('sales.show.customer_missing') }}</div>
                        </div>
                    </div>

                    <div class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.summary_title') }}
                            </span>
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-1 text-xs font-semibold"
                                    :class="orderStatusClass"
                                >
                                    {{ orderStatusLabel }}
                                </span>
                            <span
                                class="rounded-full px-2 py-1 text-xs font-semibold"
                                :class="paymentStatusClass"
                            >
                                {{ $t('sales.show.payment_label', { status: paymentStatusLabel }) }}
                            </span>
                            </div>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div class="flex items-center justify-between">
                                <span>{{ $t('sales.summary.subtotal') }}</span>
                                <span class="font-medium">{{ formatCurrency(sale.subtotal) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('sales.summary.taxes') }}</span>
                                <span class="font-medium">{{ formatCurrency(sale.tax_total) }}</span>
                            </div>
                            <div v-if="Number(sale.discount_total || 0) > 0" class="flex items-center justify-between text-emerald-700">
                                <span>{{ $t('sales.summary.discount_rate', { rate: sale.discount_rate || 0 }) }}</span>
                                <span class="font-medium">- {{ formatCurrency(sale.discount_total) }}</span>
                            </div>
                            <div v-if="Number(sale.delivery_fee || 0) > 0" class="flex items-center justify-between">
                                <span>{{ $t('sales.summary.delivery') }}</span>
                                <span class="font-medium">{{ formatCurrency(sale.delivery_fee) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                                <span class="font-semibold">{{ $t('sales.summary.total') }}</span>
                                <span class="font-semibold">{{ formatCurrency(sale.total) }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 pt-2 text-xs text-stone-500 dark:text-neutral-400">
                                <div class="flex items-center justify-between">
                                    <span>{{ $t('sales.summary.lines') }}</span>
                                    <span class="font-medium text-stone-700 dark:text-neutral-200">{{ lineCount }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>{{ $t('sales.summary.items') }}</span>
                                    <span class="font-medium text-stone-700 dark:text-neutral-200">{{ totalQty }}</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                <span>{{ $t('sales.summary.created') }}</span>
                                <span>{{ humanizeDate(sale.created_at) }}</span>
                            </div>
                            <div v-if="sale.paid_at" class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                <span>{{ $t('sales.summary.paid') }}</span>
                                <span>{{ humanizeDate(sale.paid_at) }}</span>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="showPaymentPanel"
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.payments.title') }}
                            </span>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                                {{ paymentStatusLabel }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="depositAmount > 0" class="flex items-center justify-between">
                                <span>{{ $t('sales.payments.deposit_required') }}</span>
                                <span class="font-medium">{{ formatCurrency(depositAmount) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('sales.payments.paid') }}</span>
                                <span class="font-medium">{{ formatCurrency(amountPaid) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('sales.payments.balance_due') }}</span>
                                <span class="font-medium">{{ formatCurrency(balanceDue) }}</span>
                            </div>
                        </div>
                        <form v-if="canRecordPayment" class="mt-3 space-y-2" @submit.prevent="submitPayment">
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.payments.amount_label') }}
                                </label>
                                <input
                                    v-model="paymentForm.amount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                />
                                <div v-if="paymentForm.errors.amount" class="text-xs text-red-600">
                                    {{ paymentForm.errors.amount }}
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.payments.method_label') }}
                                </label>
                                <select
                                    v-model="paymentForm.method"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                >
                                    <option value="cash">{{ $t('sales.payments.cash') }}</option>
                                    <option value="card">{{ $t('sales.payments.card') }}</option>
                                </select>
                            </div>
                            <div v-if="paymentForm.errors.payment" class="text-xs text-red-600">
                                {{ paymentForm.errors.payment }}
                            </div>
                            <button
                                type="submit"
                                class="w-full rounded-sm bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                                :disabled="paymentForm.processing"
                            >
                                {{ paymentForm.processing ? $t('sales.payments.recording') : $t('sales.payments.record') }}
                            </button>
                        </form>
                    </div>

                    <div
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.payments.history_title') }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="!paymentTimeline.length" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.payments.empty') }}
                            </div>
                            <div v-else class="space-y-2">
                                <div
                                    v-for="payment in paymentTimeline"
                                    :key="payment.id"
                                    class="flex items-center justify-between rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300"
                                >
                                    <div>
                                        <div class="text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                            {{ payment.timeline_label }}
                                        </div>
                                        <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ formatCurrency(payment.amount) }}
                                        </div>
                                        <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ paymentMethodLabel(payment.method) }} · {{ formatDateTime(payment.paid_at) }}
                                        </div>
                                    </div>
                                    <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                        {{ payment.status || '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="sale.fulfillment_method || sale.delivery_address || sale.pickup_notes || sale.delivery_notes || sale.scheduled_for"
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.fulfillment_title') }}
                            </span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ fulfillmentLabel }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-1 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="sale.fulfillment_status" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.fulfillment_status', { status: fulfillmentStatusLabels[sale.fulfillment_status] || sale.fulfillment_status }) }}
                            </div>
                            <div v-if="sale.fulfillment_method === 'delivery' && sale.delivery_address">
                                {{ $t('sales.show.address_label') }} {{ sale.delivery_address }}
                            </div>
                            <div v-if="sale.fulfillment_method === 'delivery' && sale.delivery_notes">
                                {{ $t('sales.show.notes_label') }} {{ sale.delivery_notes }}
                            </div>
                            <div v-if="sale.fulfillment_method === 'pickup' && sale.pickup_notes">
                                {{ $t('sales.show.notes_label') }} {{ sale.pickup_notes }}
                            </div>
                            <div v-if="sale.scheduled_for">
                                {{ $t('sales.show.requested_time', { date: formatDateTime(sale.scheduled_for) }) }}
                            </div>
                            <div v-if="sale.delivery_confirmed_at">
                                {{ $t('sales.show.confirmed_on', { date: formatDateTime(sale.delivery_confirmed_at) }) }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="sale.fulfillment_method === 'pickup'"
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.pickup_title') }}
                            </span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ fulfillmentStatusLabels[sale.fulfillment_status] || $t('sales.fulfillment.method.pickup') }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="pickupCode" class="flex items-center justify-between">
                                <span class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.show.pickup_code_label') }}
                                </span>
                                <span class="font-semibold text-stone-800 dark:text-neutral-100">{{ pickupCode }}</span>
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.pickup_code_pending') }}
                            </div>
                            <div v-if="sale.pickup_confirmed_at">
                                {{ $t('sales.show.pickup_confirmed_on', { date: formatDateTime(sale.pickup_confirmed_at) }) }}
                            </div>
                            <div v-if="pickupConfirmedBy?.name" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.pickup_confirmed_by', { name: pickupConfirmedBy.name }) }}
                            </div>
                        </div>
                        <div v-if="showPickupQr" class="mt-3 flex justify-center">
                            <img
                                :src="pickupQrUrl"
                                :alt="$t('sales.show.pickup_qr_alt', { code: pickupCode })"
                                class="h-40 w-40 rounded-sm border border-stone-200 bg-white object-contain p-2 dark:border-neutral-700"
                            >
                        </div>
                    </div>

                    <div
                        v-if="sale.delivery_proof_url"
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.delivery_proof_title') }}
                            </span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ sale.delivery_confirmed_at ? formatDateTime(sale.delivery_confirmed_at) : '' }}
                            </span>
                        </div>
                        <div class="mt-3">
                            <img
                                :src="sale.delivery_proof_url"
                                :alt="$t('sales.show.delivery_proof_title')"
                                class="h-44 w-full rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                            >
                        </div>
                    </div>

                    <div
                        v-if="sale.source === 'portal' || sale.customer_notes || sale.substitution_notes"
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    >
                        <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            {{ $t('sales.show.customer_notes_title') }}
                        </p>
                        <div class="mt-2 space-y-2">
                            <p v-if="sale.customer_notes">{{ sale.customer_notes }}</p>
                            <p v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.customer_notes_empty') }}
                            </p>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.show.substitutions_label') }}
                                <span class="font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ sale.substitution_allowed === false
                                        ? $t('sales.show.substitution_not_allowed')
                                        : $t('sales.show.substitution_allowed') }}
                                </span>
                            </div>
                            <p v-if="sale.substitution_notes">{{ sale.substitution_notes }}</p>
                        </div>
                    </div>

                    <div v-if="sale.notes" class="print-card rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            {{ $t('sales.show.notes_title') }}
                        </p>
                        <p class="mt-2">{{ sale.notes }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@media print {
    :global(header),
    :global(aside) {
        display: none !important;
    }
    :global(body),
    :global(#content) {
        background: white !important;
    }
    :global(#content) {
        padding: 0 !important;
    }
    :global(main#content) {
        min-height: auto !important;
    }
    .no-print {
        display: none !important;
    }
    .print-card {
        box-shadow: none !important;
    }
}
</style>
