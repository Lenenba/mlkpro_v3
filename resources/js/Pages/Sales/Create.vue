<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/UI/Modal.vue';
import AppModal from '@/Components/Modal.vue';
import CustomerQuickForm from '@/Components/QuickCreate/CustomerQuickForm.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';

const props = defineProps({
    customers: {
        type: Array,
        default: () => [],
    },
    products: {
        type: Array,
        default: () => [],
    },
    stripe: {
        type: Object,
        default: () => ({}),
    },
    paymentMethodSettings: {
        type: Object,
        default: () => ({}),
    },
    loyaltyProgram: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const localCustomers = ref([...props.customers]);

const form = useForm({
    customer_id: '',
    status: 'pending',
    notes: '',
    items: [],
    payment_method: '',
    pay_with_stripe: false,
    loyalty_points_redeem: 0,
});

const page = usePage();
const lastSaleId = computed(() => page.props.flash?.last_sale_id || null);
const loyaltyFeatureEnabled = computed(() => {
    const featureFlag = page.props.auth?.account?.features?.loyalty;
    if (typeof featureFlag === 'boolean') {
        return featureFlag;
    }

    return Boolean(props.loyaltyProgram?.feature_enabled ?? true);
});
const stripeEnabled = computed(() => Boolean(props.stripe?.enabled));
const ALLOWED_INTERNAL_METHODS = ['cash', 'card', 'bank_transfer', 'check'];

const allowedPaymentMethods = computed(() => {
    const raw = Array.isArray(props.paymentMethodSettings?.enabled_methods_internal)
        ? props.paymentMethodSettings.enabled_methods_internal
        : [];

    const normalized = raw
        .map((method) => (typeof method === 'string' ? method.trim().toLowerCase() : ''))
        .filter((method, index, array) => method && array.indexOf(method) === index)
        .filter((method) => ALLOWED_INTERNAL_METHODS.includes(method));

    return normalized.length ? normalized : ['cash', 'card'];
});

const defaultPaymentMethod = computed(() => {
    const configured = typeof props.paymentMethodSettings?.default_method_internal === 'string'
        ? props.paymentMethodSettings.default_method_internal.trim().toLowerCase()
        : '';

    if (configured && allowedPaymentMethods.value.includes(configured)) {
        return configured;
    }

    return allowedPaymentMethods.value[0] || 'cash';
});

const paymentMethodLabel = (method) => {
    if (method === 'cash') {
        return t('sales.form.payment_methods.cash.label');
    }
    if (method === 'card') {
        return t('sales.form.payment_methods.card.label');
    }
    if (method === 'bank_transfer') {
        return 'Bank transfer';
    }
    if (method === 'check') {
        return 'Check';
    }
    return method || '-';
};

const paymentMethodDescription = (method) => {
    if (method === 'cash') {
        return t('sales.form.payment_methods.cash.description');
    }
    if (method === 'card') {
        return t('sales.form.payment_methods.card.description');
    }
    if (method === 'bank_transfer') {
        return 'Manual transfer payment.';
    }
    if (method === 'check') {
        return 'Manual check payment.';
    }
    return '';
};

const stripeError = ref('');
const stripeProcessing = ref(false);
const hasChargeableTotal = computed(() => total.value > 0);
const hasCardMethodEnabled = computed(() => allowedPaymentMethods.value.includes('card'));
const canUseCardPayment = computed(() =>
    hasCardMethodEnabled.value && stripeEnabled.value && hasChargeableTotal.value
);
const paymentMethodOptions = computed(() => allowedPaymentMethods.value.map((method) => ({
    value: method,
    label: paymentMethodLabel(method),
    description: paymentMethodDescription(method),
    disabled: method === 'card' && !canUseCardPayment.value,
})));
const selectablePaymentMethods = computed(() => paymentMethodOptions.value.filter((option) => !option.disabled));
const hasMultipleSelectablePaymentMethods = computed(() => selectablePaymentMethods.value.length > 1);
const singleSelectablePaymentMethod = computed(() =>
    hasMultipleSelectablePaymentMethods.value ? null : (selectablePaymentMethods.value[0]?.value || null)
);
const hasAvailablePaymentMethod = computed(() => selectablePaymentMethods.value.length > 0);

const shouldUseStripePayment = computed(() =>
    form.status === 'paid' && form.payment_method === 'card' && canUseCardPayment.value
);

const submitLabel = computed(() => {
    if (shouldUseStripePayment.value) {
        return stripeProcessing.value
            ? t('sales.actions.pay_with_stripe_loading')
            : t('sales.actions.pay_with_stripe');
    }
    return form.status === 'paid' ? t('sales.create.save_sale') : t('sales.create.save_order');
});

const showQrModal = ref(false);
const qrCheckoutUrl = ref('');
const qrSale = ref(null);
const qrImageUrl = computed(() =>
    qrCheckoutUrl.value
        ? `https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=${encodeURIComponent(qrCheckoutUrl.value)}`
        : ''
);

const closeQrModal = () => {
    showQrModal.value = false;
};

const statusOptions = computed(() => [
    {
        value: 'pending',
        label: t('sales.create.status.pending.label'),
        description: t('sales.create.status.pending.description'),
    },
    {
        value: 'paid',
        label: t('sales.create.status.paid.label'),
        description: t('sales.create.status.paid.description'),
    },
]);

const customerOptions = computed(() =>
    localCustomers.value.map((customer) => ({
        value: customer.id,
        label: customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() || customer.email,
    }))
);

const selectedCustomer = computed(() =>
    localCustomers.value.find((customer) => customer.id === form.customer_id) || null
);

const searchQuery = ref('');
const scanQuery = ref('');
const scanError = ref('');

const productMap = computed(() => {
    const map = {};
    props.products.forEach((product) => {
        map[product.id] = product;
    });
    return map;
});

const normalize = (value) => String(value || '').toLowerCase();

const filteredProducts = computed(() => {
    const query = normalize(searchQuery.value);
    if (!query) {
        return props.products;
    }
    return props.products.filter((product) => {
        return (
            normalize(product.name).includes(query)
            || normalize(product.sku).includes(query)
            || normalize(product.barcode).includes(query)
        );
    });
});

const parseNumber = (value) => {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
};

const isOutOfStock = (product) => parseNumber(product?.stock) <= 0;

const isLowStock = (product) => {
    const stock = parseNumber(product?.stock);
    const min = parseNumber(product?.minimum_stock);
    return stock > 0 && min > 0 && stock <= min;
};

const addProduct = (product) => {
    if (!product) {
        return;
    }
    if (isOutOfStock(product)) {
        scanError.value = t('sales.form.errors.out_of_stock');
        return;
    }
    const existingIndex = form.items.findIndex((item) => item.product_id === product.id);
    if (existingIndex >= 0) {
        scanError.value = '';
        form.items[existingIndex].quantity = parseNumber(form.items[existingIndex].quantity) + 1;
        return;
    }
    scanError.value = '';
    form.items.push({
        product_id: product.id,
        quantity: 1,
        price: product.price ?? 0,
        description: product.name ?? '',
    });
};

const updateQuantity = (index, nextValue) => {
    const item = form.items[index];
    if (!item) {
        return;
    }
    const value = Math.max(1, parseNumber(nextValue));
    item.quantity = value;
};

const incrementItem = (index) => {
    updateQuantity(index, parseNumber(form.items[index]?.quantity) + 1);
};

const decrementItem = (index) => {
    const item = form.items[index];
    if (!item) {
        return;
    }
    const nextValue = parseNumber(item.quantity) - 1;
    if (nextValue <= 0) {
        form.items.splice(index, 1);
        return;
    }
    item.quantity = nextValue;
};

const removeItem = (index) => {
    form.items.splice(index, 1);
};

const handleScan = () => {
    const query = scanQuery.value.trim();
    if (!query) {
        return;
    }
    const match = props.products.find((product) => {
        return String(product.barcode || '') === query || String(product.sku || '') === query;
    });
    if (!match) {
        scanError.value = t('sales.form.errors.product_not_found');
        return;
    }
    scanError.value = '';
    addProduct(match);
    scanQuery.value = '';
};

const subtotal = computed(() =>
    form.items.reduce((total, item) => {
        const quantity = parseNumber(item.quantity);
        const price = parseNumber(item.price);
        return total + quantity * price;
    }, 0)
);

const taxTotal = computed(() =>
    form.items.reduce((total, item) => {
        const product = productMap.value[item.product_id];
        const taxRate = parseNumber(product?.tax_rate);
        if (!taxRate) {
            return total;
        }
        const lineTotal = parseNumber(item.quantity) * parseNumber(item.price);
        return total + (lineTotal * taxRate) / 100;
    }, 0)
);

const discountRate = computed(() => Number(selectedCustomer.value?.discount_rate || 0));
const discountTotal = computed(() => subtotal.value * (discountRate.value / 100));
const discountedTaxTotal = computed(() => taxTotal.value * (1 - discountRate.value / 100));
const totalBeforeLoyalty = computed(() =>
    Math.max(0, subtotal.value - discountTotal.value) + discountedTaxTotal.value
);
const loyaltyEnabled = computed(() =>
    loyaltyFeatureEnabled.value && Boolean(props.loyaltyProgram?.is_enabled)
);
const loyaltyRate = computed(() => {
    const value = Number(props.loyaltyProgram?.points_per_currency_unit || 0);
    return Number.isFinite(value) && value > 0 ? value : 0;
});
const loyaltyLabel = computed(() => String(props.loyaltyProgram?.points_label || 'points'));
const customerLoyaltyBalance = computed(() => Number(selectedCustomer.value?.loyalty_points_balance || 0));
const maxRedeemablePoints = computed(() => {
    if (!selectedCustomer.value || !loyaltyEnabled.value || loyaltyRate.value <= 0) {
        return 0;
    }

    const byAmount = Math.floor(Math.max(0, totalBeforeLoyalty.value) * loyaltyRate.value);
    return Math.max(0, Math.min(customerLoyaltyBalance.value, byAmount));
});
const loyaltyPointsRedeem = computed(() => {
    const requested = Math.floor(Math.max(0, parseNumber(form.loyalty_points_redeem)));
    return Math.min(requested, maxRedeemablePoints.value);
});
const loyaltyDiscountTotal = computed(() => {
    if (loyaltyRate.value <= 0) {
        return 0;
    }
    return Math.min(totalBeforeLoyalty.value, loyaltyPointsRedeem.value / loyaltyRate.value);
});
const total = computed(() =>
    Math.max(0, totalBeforeLoyalty.value - loyaltyDiscountTotal.value)
);
const canStripeCheckout = computed(() => total.value > 0);

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const handleCustomerCreated = (payload) => {
    const customer = payload?.customer;
    if (!customer) {
        return;
    }
    const existingIndex = localCustomers.value.findIndex((item) => item.id === customer.id);
    if (existingIndex >= 0) {
        localCustomers.value.splice(existingIndex, 1, customer);
    } else {
        localCustomers.value.unshift(customer);
    }
    form.customer_id = customer.id;
};

const resetForm = () => {
    form.reset();
    form.payment_method = selectablePaymentMethods.value[0]?.value || defaultPaymentMethod.value;
    form.clearErrors();
    searchQuery.value = '';
    scanQuery.value = '';
    scanError.value = '';
};

watch(
    () => [paymentMethodOptions.value, defaultPaymentMethod.value],
    () => {
        const currentOption = paymentMethodOptions.value.find((option) => option.value === form.payment_method);
        if (currentOption && !currentOption.disabled) {
            return;
        }

        const preferredOption = paymentMethodOptions.value.find((option) =>
            option.value === defaultPaymentMethod.value && !option.disabled
        );
        form.payment_method = preferredOption?.value
            || selectablePaymentMethods.value[0]?.value
            || defaultPaymentMethod.value;
    },
    { immediate: true, deep: true }
);

watch(
    () => [form.customer_id, maxRedeemablePoints.value],
    () => {
        if (!selectedCustomer.value || !loyaltyEnabled.value) {
            form.loyalty_points_redeem = 0;
            return;
        }

        const capped = Math.min(
            Math.floor(Math.max(0, parseNumber(form.loyalty_points_redeem))),
            maxRedeemablePoints.value
        );
        if (capped !== form.loyalty_points_redeem) {
            form.loyalty_points_redeem = capped;
        }
    },
    { immediate: true }
);

const submit = () => {
    stripeError.value = '';
    form.loyalty_points_redeem = loyaltyPointsRedeem.value;
    if (form.status === 'paid' && !hasAvailablePaymentMethod.value) {
        stripeError.value = t('sales.form.payment_methods.card.disabled_hint');
        return;
    }

    form.pay_with_stripe = shouldUseStripePayment.value;
    if (shouldUseStripePayment.value) {
        if (!canStripeCheckout.value) {
            stripeError.value = t('sales.errors.stripe_amount_invalid');
            return;
        }
        stripeProcessing.value = true;
        const payload = {
            customer_id: form.customer_id,
            status: form.status,
            notes: form.notes,
            items: form.items,
            payment_method: form.payment_method,
            pay_with_stripe: true,
            loyalty_points_redeem: loyaltyPointsRedeem.value,
        };

        axios.post(route('sales.store'), payload, { headers: { Accept: 'application/json' } })
            .then((response) => {
                const data = response?.data || {};
                qrCheckoutUrl.value = data.checkout_url || '';
                qrSale.value = data.sale || null;
                showQrModal.value = Boolean(qrCheckoutUrl.value);
            })
            .catch((error) => {
                const message = error?.response?.data?.message
                    || error?.response?.data?.errors?.status?.[0]
                    || t('sales.errors.stripe_start');
                stripeError.value = message;
            })
            .finally(() => {
                stripeProcessing.value = false;
            });
        return;
    }

    form.post(route('sales.store'), {
        preserveScroll: true,
        onSuccess: () => resetForm(),
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('sales.create.title')" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('sales.create.title') }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('sales.create.subtitle') }}
                    </p>
                </div>
                <Link
                    :href="route('sales.index')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ $t('sales.actions.back_to_sales') }}
                </Link>
            </div>

            <div
                v-if="lastSaleId"
                class="rounded-sm border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200"
            >
                {{ $t('sales.create.flash_saved') }}
                <Link :href="route('sales.show', lastSaleId)" class="font-semibold underline">
                    {{ $t('sales.create.flash_view') }}
                </Link>
            </div>

            <form @submit.prevent="submit" class="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_1fr]">
                <div class="space-y-4 lg:sticky lg:top-24 lg:self-start">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex flex-col gap-3 md:flex-row">
                            <div class="flex-1">
                                <label class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.form.search_label') }}
                                </label>
                                <input
                                    v-model="searchQuery"
                                    type="text"
                                    :placeholder="$t('sales.form.search_placeholder')"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                />
                            </div>
                            <div class="md:w-64">
                                <label class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.form.scan_label') }}
                                </label>
                                <input
                                    v-model="scanQuery"
                                    type="text"
                                    :placeholder="$t('sales.form.scan_placeholder')"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    @keydown.enter.prevent="handleScan"
                                />
                                <p v-if="scanError" class="mt-1 text-xs text-red-600">{{ scanError }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <button
                            v-for="product in filteredProducts"
                            :key="product.id"
                            type="button"
                            class="group rounded-sm border border-stone-200 bg-white p-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900"
                            :class="isOutOfStock(product) ? 'opacity-50 cursor-not-allowed' : ''"
                            :disabled="isOutOfStock(product)"
                            @click="addProduct(product)"
                        >
                            <div class="flex items-start gap-3">
                                <img
                                    :src="product.image_url || product.image"
                                    :alt="product.name"
                                    class="h-14 w-14 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <div class="flex-1 space-y-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ product.name }}
                                        </h3>
                                        <span class="text-xs font-semibold text-green-700 dark:text-green-400">
                                            {{ formatCurrency(product.price) }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ product.sku || product.barcode || $t('sales.labels.no_code') }}
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="isOutOfStock(product)
                                                ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300'
                                                : (isLowStock(product)
                                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                                    : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300')"
                                        >
                                            {{ isOutOfStock(product)
                                                ? $t('sales.stock.out')
                                                : (isLowStock(product) ? $t('sales.stock.low') : $t('sales.stock.available')) }}
                                        </span>
                                        <span class="text-stone-500 dark:text-neutral-400">
                                            {{ $t('sales.labels.stock') }} {{ product.stock ?? 0 }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <div class="flex items-center justify-end">
                                    <button
                                        type="button"
                                        data-hs-overlay="#pos-quick-customer"
                                        class="text-[11px] font-semibold text-green-700 hover:underline dark:text-green-400"
                                    >
                                        {{ $t('sales.form.new_customer') }}
                                    </button>
                                </div>
                                <FloatingSelect
                                    v-model="form.customer_id"
                                    :label="$t('sales.form.customer_label')"
                                    :options="customerOptions"
                                    :placeholder="$t('sales.form.customer_placeholder')"
                                />
                                <InputError class="mt-1" :message="form.errors.customer_id" />
                                <div
                                    v-if="selectedCustomer"
                                    class="mt-2 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="font-semibold text-stone-700 dark:text-neutral-100">
                                            {{ selectedCustomer.company_name || `${selectedCustomer.first_name || ''} ${selectedCustomer.last_name || ''}`.trim() || selectedCustomer.email }}
                                        </span>
                                        <span
                                            v-if="discountRate > 0"
                                            class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200"
                                        >
                                            {{ $t('sales.form.discount_badge', { rate: discountRate }) }}
                                        </span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                        <span v-if="selectedCustomer.email">{{ selectedCustomer.email }}</span>
                                        <span v-if="selectedCustomer.phone">{{ selectedCustomer.phone }}</span>
                                    </div>
                                    <div
                                        v-if="loyaltyEnabled"
                                        class="mt-2 text-[11px] text-stone-500 dark:text-neutral-400"
                                    >
                                        {{ $t('sales.form.loyalty.balance', { points: Number(customerLoyaltyBalance || 0).toLocaleString(), label: loyaltyLabel }) }}
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.form.type_label') }}
                                </label>
                                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <button
                                        v-for="option in statusOptions"
                                        :key="option.value"
                                        type="button"
                                        class="rounded-sm border px-3 py-2 text-left transition"
                                        :class="form.status === option.value
                                            ? 'border-green-500 bg-green-50 text-green-700'
                                            : 'border-stone-200 text-stone-600 hover:border-stone-300 dark:border-neutral-700 dark:text-neutral-300'"
                                        @click="form.status = option.value"
                                    >
                                        <div class="flex items-center gap-2 text-sm font-semibold">
                                            <span
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-sm border border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                            >
                                                <svg v-if="option.value === 'pending'" class="size-4" xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                    stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 3h18v18H3z" />
                                                    <path d="M7 7h10" />
                                                    <path d="M7 12h6" />
                                                    <path d="M7 17h4" />
                                                </svg>
                                                <svg v-else class="size-4" xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                    stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M12 20h9" />
                                                    <path d="M12 4h9" />
                                                    <path d="M4 12h16" />
                                                    <path d="M4 6h2" />
                                                    <path d="M4 18h2" />
                                                </svg>
                                            </span>
                                            <span>{{ option.label }}</span>
                                        </div>
                                        <p class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ option.description }}
                                        </p>
                                    </button>
                                </div>
                                <InputError class="mt-1" :message="form.errors.status" />
                            </div>
                            <div v-if="form.status === 'paid'">
                                <label class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.form.payment_label') }}
                                </label>
                                <div v-if="hasMultipleSelectablePaymentMethods" class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <button
                                        v-for="option in selectablePaymentMethods"
                                        :key="option.value"
                                        type="button"
                                        class="rounded-sm border px-3 py-2 text-left transition"
                                        :class="
                                            form.payment_method === option.value
                                                ? 'border-green-500 bg-green-50 text-green-700'
                                                : 'border-stone-200 text-stone-600 hover:border-stone-300 dark:border-neutral-700 dark:text-neutral-300'
                                        "
                                        @click="form.payment_method = option.value"
                                    >
                                        <div class="flex items-center gap-2 text-sm font-semibold">
                                            <span
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-sm border border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                            >
                                                <svg v-if="option.value === 'cash'" class="size-4" xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                    stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="2" y="6" width="20" height="12" rx="2" />
                                                    <circle cx="12" cy="12" r="3" />
                                                </svg>
                                                <svg v-else class="size-4" xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                    stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="2" y="5" width="20" height="14" rx="2" />
                                                    <path d="M2 10h20" />
                                                </svg>
                                            </span>
                                            <span>{{ option.label }}</span>
                                        </div>
                                        <p class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ option.description }}
                                        </p>
                                    </button>
                                </div>
                                <div
                                    v-else-if="singleSelectablePaymentMethod"
                                    class="mt-2 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    Payment method:
                                    <span class="font-semibold text-stone-700 dark:text-neutral-200">
                                        {{ paymentMethodLabel(singleSelectablePaymentMethod) }}
                                    </span>
                                </div>
                                <div
                                    v-else
                                    class="mt-2 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                >
                                    No payment method is currently available.
                                </div>
                                <p v-if="hasCardMethodEnabled && !canUseCardPayment" class="mt-2 text-xs text-amber-600 dark:text-amber-300">
                                    {{ $t('sales.form.payment_methods.card.disabled_hint') }}
                                </p>
                                <InputError class="mt-1" :message="form.errors.payment_method" />
                            </div>

                            <div v-if="selectedCustomer && loyaltyEnabled">
                                <label class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.form.loyalty.redeem_label') }}
                                </label>
                                <input
                                    v-model.number="form.loyalty_points_redeem"
                                    type="number"
                                    min="0"
                                    :max="maxRedeemablePoints"
                                    step="1"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 disabled:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    :disabled="maxRedeemablePoints <= 0"
                                />
                                <p class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                    {{ $t('sales.form.loyalty.hint', {
                                        max: Number(maxRedeemablePoints || 0).toLocaleString(),
                                        label: loyaltyLabel,
                                        amount: formatCurrency(loyaltyDiscountTotal),
                                    }) }}
                                </p>
                                <InputError class="mt-1" :message="form.errors.loyalty_points_redeem" />
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('sales.form.invoice_title') }}
                            </h2>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('sales.form.lines', { count: form.items.length }) }}
                            </span>
                        </div>

                        <div v-if="!form.items.length" class="mt-4 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('sales.form.empty_items') }}
                        </div>

                        <div v-else class="mt-4 max-h-[45vh] space-y-3 overflow-y-auto pr-1">
                            <div
                                v-for="(item, index) in form.items"
                                :key="`${item.product_id}-${index}`"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ productMap[item.product_id]?.name || item.description }}
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ formatCurrency(item.price) }}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-red-600 hover:text-red-700"
                                        @click="removeItem(index)"
                                    >
                                        {{ $t('sales.form.remove_item') }}
                                    </button>
                                </div>
                                <div class="mt-3 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="h-7 w-7 rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            @click="decrementItem(index)"
                                        >
                                            -
                                        </button>
                                        <input
                                            :value="item.quantity"
                                            type="number"
                                            min="1"
                                            class="h-7 w-14 rounded-sm border border-stone-200 text-center text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                            @input="updateQuantity(index, $event.target.value)"
                                        />
                                        <button
                                            type="button"
                                            class="h-7 w-7 rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            @click="incrementItem(index)"
                                        >
                                            +
                                        </button>
                                    </div>
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(parseNumber(item.quantity) * parseNumber(item.price)) }}
                                    </div>
                                </div>
                                <InputError class="mt-1" :message="form.errors[`items.${index}.quantity`]" />
                                <InputError class="mt-1" :message="form.errors[`items.${index}.product_id`]" />
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <label class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('sales.form.notes_label') }}
                        </label>
                        <textarea
                            v-model="form.notes"
                            rows="3"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        />
                        <InputError class="mt-1" :message="form.errors.notes" />
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div class="flex items-center justify-between">
                                <span>{{ $t('sales.summary.subtotal') }}</span>
                                <span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('sales.summary.taxes') }}</span>
                                <span class="font-medium">{{ formatCurrency(discountedTaxTotal) }}</span>
                            </div>
                            <div v-if="discountRate > 0" class="flex items-center justify-between text-emerald-700">
                                <span>{{ $t('sales.summary.discount_rate', { rate: discountRate }) }}</span>
                                <span class="font-medium">- {{ formatCurrency(discountTotal) }}</span>
                            </div>
                            <div v-if="loyaltyPointsRedeem > 0" class="flex items-center justify-between text-emerald-700">
                                <span>{{ $t('sales.summary.loyalty_redeem', { points: loyaltyPointsRedeem, label: loyaltyLabel }) }}</span>
                                <span class="font-medium">- {{ formatCurrency(loyaltyDiscountTotal) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                                <span class="font-semibold">{{ $t('sales.summary.total') }}</span>
                                <span class="font-semibold">{{ formatCurrency(total) }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="stripeError" class="text-xs text-red-600 dark:text-red-300">
                        {{ stripeError }}
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing || stripeProcessing || !form.items.length || (form.status === 'paid' && !hasAvailablePaymentMethod)"
                        class="w-full rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                    >
                        {{ submitLabel }}
                    </button>
                </div>
            </form>
        </div>

        <Modal :title="$t('sales.form.new_customer_title')" id="pos-quick-customer">
            <CustomerQuickForm
                :overlay-id="'#pos-quick-customer'"
                :submit-label="$t('sales.form.create_customer')"
                :close-on-success="true"
                @created="handleCustomerCreated"
            />
        </Modal>

        <AppModal :show="showQrModal" maxWidth="md" @close="closeQrModal">
            <div class="p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('sales.qr.title') }}
                    </h2>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('sales.qr.subtitle') }}
                    </p>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 text-center dark:border-neutral-700 dark:bg-neutral-900">
                    <img
                        v-if="qrImageUrl"
                        :src="qrImageUrl"
                        :alt="$t('sales.qr.image_alt')"
                        class="mx-auto h-56 w-56 rounded-sm border border-stone-200 bg-white p-2"
                        loading="lazy"
                        decoding="async"
                    />
                    <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('sales.qr.loading') }}
                    </div>
                </div>

                <div class="space-y-2 text-xs text-stone-600 dark:text-neutral-300">
                    <div v-if="qrSale?.number">
                        {{ $t('sales.qr.order_label', { number: qrSale.number }) }}
                    </div>
                    <div v-else-if="qrSale?.id">
                        {{ $t('sales.qr.order_label', { number: `#${qrSale.id}` }) }}
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Link
                        v-if="qrSale?.id"
                        :href="route('sales.show', qrSale.id)"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    >
                        {{ $t('sales.qr.view_sale') }}
                    </Link>
                    <a
                        v-if="qrCheckoutUrl"
                        :href="qrCheckoutUrl"
                        target="_blank"
                        rel="noreferrer"
                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                    >
                        {{ $t('sales.qr.open_link') }}
                    </a>
                </div>
            </div>
        </AppModal>
    </AuthenticatedLayout>
</template>
