<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    company: {
        type: Object,
        default: () => ({}),
    },
    customer: {
        type: Object,
        default: () => ({}),
    },
    fulfillment: {
        type: Object,
        default: () => ({}),
    },
    order: {
        type: Object,
        required: true,
    },
    payments: {
        type: Array,
        default: () => [],
    },
    stripe: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const order = computed(() => props.order || {});
const stripeEnabled = computed(() => Boolean(props.stripe?.enabled));

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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

const orderNumber = computed(() => order.value?.number || t('client_orders.labels.order_label', { id: order.value?.id || '-' }));

const paymentStatusLabels = computed(() => ({
    draft: t('client_orders.status.draft'),
    pending: t('client_orders.status.pending'),
    unpaid: t('client_orders.status.unpaid'),
    deposit_required: t('client_orders.status.deposit_required'),
    partial: t('client_orders.status.partial'),
    paid: t('client_orders.status.paid'),
    canceled: t('client_orders.status.canceled'),
}));

const fulfillmentLabels = computed(() => ({
    pending: t('client_orders.fulfillment.pending'),
    preparing: t('client_orders.fulfillment.preparing'),
    out_for_delivery: t('client_orders.fulfillment.out_for_delivery'),
    ready_for_pickup: t('client_orders.fulfillment.ready_for_pickup'),
    completed: t('client_orders.fulfillment.completed'),
    confirmed: t('client_orders.fulfillment.confirmed'),
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

const fulfillmentClasses = {
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    preparing: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-200',
    out_for_delivery: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    ready_for_pickup: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-200',
    completed: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    confirmed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
};

const orderStatusLabel = computed(() => {
    if (!order.value?.fulfillment_status) {
        return t('client_orders.fulfillment.pending');
    }
    if (order.value.fulfillment_status === 'completed' && !order.value.delivery_confirmed_at) {
        return t('client_orders.fulfillment.completed_unconfirmed');
    }
    return fulfillmentLabels.value[order.value.fulfillment_status] || order.value.fulfillment_status;
});

const orderStatusClass = computed(() => {
    if (order.value?.fulfillment_status) {
        return fulfillmentClasses[order.value.fulfillment_status] || statusClasses.pending;
    }
    return statusClasses[order.value?.status] || statusClasses.pending;
});

const paymentStatusKey = computed(() => order.value?.payment_status || order.value?.status || 'pending');
const paymentStatusLabel = computed(() =>
    paymentStatusLabels.value[paymentStatusKey.value] || paymentStatusKey.value || t('client_orders.status.pending')
);

const paymentStatusClass = computed(() => paymentStatusClasses[paymentStatusKey.value] || statusClasses.pending);

const amountPaid = computed(() => Number(order.value?.amount_paid || 0));
const balanceDue = computed(() => Number(order.value?.balance_due || 0));
const depositAmount = computed(() => {
    const saved = Number(order.value?.deposit_amount || 0);
    if (saved > 0) {
        return saved;
    }
    if (order.value?.fulfillment_status === 'preparing') {
        return Math.round(Number(order.value?.total || 0) * 0.2 * 100) / 100;
    }
    return 0;
});
const depositDue = computed(() => Math.max(0, depositAmount.value - amountPaid.value));

const canPayDeposit = computed(() =>
    stripeEnabled.value
    && depositDue.value > 0
    && order.value?.status !== 'canceled'
);

const canPayBalance = computed(() =>
    stripeEnabled.value
    && balanceDue.value > 0
    && order.value?.status !== 'canceled'
);

const paymentProcessing = ref(false);
const paymentError = ref('');

const startPayment = (type) => {
    if (!order.value?.id || paymentProcessing.value) {
        return;
    }
    paymentProcessing.value = true;
    paymentError.value = '';
    router.post(route('portal.orders.pay', order.value.id), { type }, {
        preserveScroll: true,
        onError: (errors) => {
            paymentError.value = errors.payment || errors.message || t('portal_shop.payment.error');
        },
        onFinish: () => {
            paymentProcessing.value = false;
        },
    });
};

const canConfirmReceipt = computed(() =>
    order.value?.fulfillment_status === 'completed' && !order.value?.delivery_confirmed_at
);

const confirmForm = useForm({
    proof: null,
});

const handleProofSelected = (event) => {
    const file = event.target?.files?.[0] || null;
    confirmForm.proof = file;
};

const submitReceiptConfirm = () => {
    if (!order.value?.id || !canConfirmReceipt.value) {
        return;
    }
    confirmForm.post(route('portal.orders.confirm', order.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            confirmForm.reset();
        },
    });
};

const companyInitials = computed(() => {
    const name = props.company?.name || '';
    const parts = name.split(' ').filter(Boolean).slice(0, 2);
    if (!parts.length) {
        return 'CI';
    }
    return parts.map((part) => part[0]).join('').toUpperCase();
});

const productImage = (item) => item?.product?.image_url || item?.product?.image || null;
const productFallback = (item) => {
    const label = `${item?.product?.name || item?.description || ''}`.trim();
    if (!label) {
        return '--';
    }
    return label.slice(0, 2).toUpperCase();
};

const fulfillmentLabel = computed(() => {
    if (order.value?.fulfillment_method === 'delivery') {
        return t('portal_shop.fulfillment.delivery');
    }
    if (order.value?.fulfillment_method === 'pickup') {
        return t('portal_shop.fulfillment.pickup');
    }
    return t('sales.fulfillment.method.order');
});

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
    const list = Array.isArray(props.payments) ? props.payments : [];
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
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('portal_order.title', { number: orderNumber })" />

        <div class="space-y-4">
            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="h-12 w-12 overflow-hidden rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-800">
                            <img
                                v-if="company?.logo_url"
                                :src="company.logo_url"
                                :alt="company?.name || t('portal_shop.header.logo_alt')"
                                class="h-full w-full object-cover"
                            >
                            <div v-else class="flex h-full w-full items-center justify-center text-xs font-semibold text-stone-500 dark:text-neutral-400">
                                {{ companyInitials }}
                            </div>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ company?.name || t('portal_shop.header.company_fallback') }}
                            </p>
                            <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                {{ t('portal_order.title', { number: orderNumber }) }}
                            </h1>
                            <p class="text-sm text-stone-600 dark:text-neutral-400">
                                {{ t('portal_order.subtitle') }}
                            </p>
                        </div>
                    </div>
                    <div class="space-y-1 text-sm text-stone-600 dark:text-neutral-400">
                        <div class="flex items-center justify-end gap-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="orderStatusClass"
                            >
                                {{ t('portal_order.labels.order_status') }}: {{ orderStatusLabel }}
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="paymentStatusClass"
                            >
                                {{ t('portal_order.labels.payment_status') }}: {{ paymentStatusLabel }}
                            </span>
                        </div>
                        <div class="text-right text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('portal_order.labels.created_on', { date: formatDate(order.created_at) }) }}
                        </div>
                        <div v-if="order.paid_at" class="text-right text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('portal_order.labels.paid_on', { date: formatDate(order.paid_at) }) }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <button
                    v-if="canPayDeposit"
                    type="button"
                    class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                    :disabled="paymentProcessing"
                    @click="startPayment('deposit')"
                >
                    {{ paymentProcessing ? t('portal_shop.payment.processing') : t('portal_shop.payment.pay_deposit') }}
                </button>
                <button
                    v-if="canPayBalance"
                    type="button"
                    class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                    :disabled="paymentProcessing"
                    @click="startPayment('balance')"
                >
                    {{ paymentProcessing ? t('portal_shop.payment.processing') : t('portal_shop.payment.pay_balance') }}
                </button>
                <a
                    v-if="order.id"
                    :href="route('portal.orders.pdf', order.id)"
                    target="_blank"
                    rel="noopener"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ t('portal_order.actions.download_pdf') }}
                </a>
                <Link
                    :href="route('dashboard')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ t('portal_order.actions.back_to_dashboard') }}
                </Link>
            </div>

            <div v-if="paymentError" class="text-right text-xs text-red-600 dark:text-red-300">
                {{ paymentError }}
            </div>

            <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <div class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-stone-200 px-4 py-3 text-sm font-semibold text-stone-800 dark:border-neutral-700 dark:text-neutral-100">
                        {{ t('portal_order.sections.items') }}
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-stone-50 text-xs uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3 text-left">{{ t('portal_order.table.product') }}</th>
                                    <th class="px-4 py-3 text-left">{{ t('portal_order.table.sku') }}</th>
                                    <th class="px-4 py-3 text-left">{{ t('portal_order.table.unit') }}</th>
                                    <th class="px-4 py-3 text-right">{{ t('portal_order.table.quantity') }}</th>
                                    <th class="px-4 py-3 text-right">{{ t('portal_order.table.price') }}</th>
                                    <th class="px-4 py-3 text-right">{{ t('portal_order.table.total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-800">
                                <tr v-for="item in order.items" :key="item.id">
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        <div class="flex items-center gap-3">
                                            <div class="h-10 w-10 overflow-hidden rounded-sm bg-stone-100 text-[10px] font-semibold text-stone-400 dark:bg-neutral-800 dark:text-neutral-500">
                                                <img
                                                    v-if="productImage(item)"
                                                    :src="productImage(item)"
                                                    :alt="item.product?.name || item.description"
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
                                                <div v-if="item.description && item.description !== item.product?.name" class="text-xs text-stone-500 dark:text-neutral-400">
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
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">{{ t('portal_order.sections.customer') }}</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ customer?.name || t('sales.labels.customer') }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-1 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="customer?.email">{{ customer.email }}</div>
                            <div v-if="customer?.phone">{{ customer.phone }}</div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">{{ t('portal_order.sections.summary') }}</span>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="orderStatusClass">
                                {{ orderStatusLabel }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div class="flex items-center justify-between">
                                <span>{{ t('portal_shop.summary.subtotal') }}</span>
                                <span class="font-medium">{{ formatCurrency(order.subtotal) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ t('portal_shop.summary.taxes') }}</span>
                                <span class="font-medium">{{ formatCurrency(order.tax_total) }}</span>
                            </div>
                            <div v-if="Number(order.discount_total || 0) > 0" class="flex items-center justify-between text-emerald-700">
                                <span>{{ t('sales.summary.discount_rate', { rate: order.discount_rate || 0 }) }}</span>
                                <span class="font-medium">- {{ formatCurrency(order.discount_total) }}</span>
                            </div>
                            <div v-if="Number(order.delivery_fee || 0) > 0" class="flex items-center justify-between">
                                <span>{{ t('portal_shop.summary.delivery') }}</span>
                                <span class="font-medium">{{ formatCurrency(order.delivery_fee) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                                <span class="font-semibold">{{ t('portal_shop.summary.total') }}</span>
                                <span class="font-semibold">{{ formatCurrency(order.total) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                <span>{{ t('portal_order.labels.created_on', { date: formatDate(order.created_at) }) }}</span>
                                <span>{{ humanizeDate(order.created_at) }}</span>
                            </div>
                            <div v-if="order.paid_at" class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                <span>{{ t('portal_order.labels.paid_on', { date: formatDate(order.paid_at) }) }}</span>
                                <span>{{ humanizeDate(order.paid_at) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">{{ t('portal_order.sections.payment') }}</span>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="paymentStatusClass">
                                {{ paymentStatusLabel }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="depositAmount > 0" class="flex items-center justify-between">
                                <span>{{ t('portal_shop.payment.deposit_required') }}</span>
                                <span class="font-medium">{{ formatCurrency(depositAmount) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ t('portal_shop.payment.amount_paid') }}</span>
                                <span class="font-medium">{{ formatCurrency(amountPaid) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ t('portal_shop.payment.balance_due') }}</span>
                                <span class="font-medium">{{ formatCurrency(balanceDue) }}</span>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="order.fulfillment_method || order.delivery_address || order.pickup_notes || order.delivery_notes || order.scheduled_for"
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">{{ t('portal_order.sections.fulfillment') }}</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ fulfillmentLabel }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-1 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="order.fulfillment_status" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('sales.show.fulfillment_status', { status: fulfillmentLabels[order.fulfillment_status] || order.fulfillment_status }) }}
                            </div>
                            <div v-if="order.fulfillment_method === 'delivery' && order.delivery_address">
                                {{ t('portal_order.labels.delivery_address') }}: {{ order.delivery_address }}
                            </div>
                            <div v-if="order.fulfillment_method === 'delivery' && order.delivery_notes">
                                {{ t('portal_order.labels.notes') }}: {{ order.delivery_notes }}
                            </div>
                            <div v-if="order.fulfillment_method === 'pickup' && fulfillment?.pickup_address">
                                {{ t('portal_shop.fulfillment.pickup_address_label') }}: {{ fulfillment.pickup_address }}
                            </div>
                            <div v-if="order.fulfillment_method === 'pickup' && order.pickup_notes">
                                {{ t('portal_order.labels.notes') }}: {{ order.pickup_notes }}
                            </div>
                            <div v-if="order.scheduled_for">
                                {{ t('portal_order.labels.requested_time', { date: formatDateTime(order.scheduled_for) }) }}
                            </div>
                            <div v-if="order.delivery_confirmed_at">
                                {{ t('sales.show.confirmed_on', { date: formatDateTime(order.delivery_confirmed_at) }) }}
                            </div>
                        </div>
                    </div>

                    <div v-if="order.delivery_proof_url" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ t('portal_shop.status.delivery_photo') }}</p>
                        <img
                            :src="order.delivery_proof_url"
                            :alt="t('portal_shop.status.delivery_photo_alt')"
                            class="mt-2 h-40 w-full rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                        >
                    </div>

                    <div v-if="canConfirmReceipt" class="rounded-sm border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                        <p class="font-semibold">{{ t('portal_shop.confirm_receipt.title') }}</p>
                        <p class="text-xs text-emerald-700">{{ t('portal_shop.confirm_receipt.subtitle') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input
                                type="file"
                                accept="image/*"
                                class="text-xs text-stone-600 file:mr-2 file:rounded-sm file:border-0 file:bg-white file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-stone-700"
                                @change="handleProofSelected"
                            >
                            <button
                                type="button"
                                class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                                :disabled="confirmForm.processing"
                                @click="submitReceiptConfirm"
                            >
                                {{ t('portal_shop.confirm_receipt.action') }}
                            </button>
                        </div>
                        <div v-if="confirmForm.errors.proof" class="mt-1 text-xs text-red-600">
                            {{ confirmForm.errors.proof }}
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">{{ t('portal_order.sections.payments') }}</span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="!paymentTimeline.length" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('portal_order.empty.payments') }}
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
                                            {{ formatCurrency(payment.amount) }} - {{ paymentMethodLabel(payment.method) }}
                                        </div>
                                        <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ payment.paid_at ? formatDateTime(payment.paid_at) : '-' }}
                                        </div>
                                    </div>
                                    <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                        {{ payment.status || '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
