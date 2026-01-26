<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';

const props = defineProps({
    company: {
        type: Object,
        default: () => ({}),
    },
    customer: {
        type: Object,
        default: () => ({}),
    },
    products: {
        type: Array,
        default: () => [],
    },
    categories: {
        type: Array,
        default: () => [],
    },
    fulfillment: {
        type: Object,
        default: () => ({}),
    },
    stripe: {
        type: Object,
        default: () => ({}),
    },
    order: {
        type: Object,
        default: () => null,
    },
    timeline: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
const cartRestored = ref(false);

const order = computed(() => props.order || null);
const isEditing = computed(() => Boolean(order.value?.id));
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
const orderStatusLabel = computed(() => {
    if (!order.value?.fulfillment_status) {
        return t('client_orders.fulfillment.pending');
    }
    if (order.value.fulfillment_status === 'completed' && !order.value.delivery_confirmed_at) {
        return t('client_orders.fulfillment.completed_unconfirmed');
    }
    return fulfillmentLabels.value[order.value.fulfillment_status] || order.value.fulfillment_status;
});
const paymentStatusKey = computed(() => order.value?.payment_status || order.value?.status || 'pending');
const paymentStatusLabel = computed(() =>
    paymentStatusLabels.value[paymentStatusKey.value] || paymentStatusKey.value || t('client_orders.status.pending')
);
const canEditOrder = computed(() => {
    if (!isEditing.value) {
        return true;
    }
    return order.value?.can_edit !== false;
});
const isLocked = computed(() => isEditing.value && !canEditOrder.value);
const pageTitle = computed(() => (isEditing.value ? t('portal_shop.page_title.edit') : t('portal_shop.page_title.create')));
const companyName = computed(() => props.company?.name || t('portal_shop.header.company_fallback'));
const orderLabel = computed(() => {
    if (order.value?.number) {
        return order.value.number;
    }
    if (order.value?.id) {
        return t('portal_shop.order_number', { id: order.value.id });
    }
    return t('portal_shop.order_fallback');
});
const headerTitle = computed(() => (isEditing.value
    ? t('portal_shop.header.edit_title', { order: orderLabel.value })
    : t('portal_shop.header.create_title', { company: companyName.value })
));
const headerSubtitle = computed(() => (isEditing.value
    ? t('portal_shop.header.edit_subtitle')
    : t('portal_shop.header.create_subtitle')
));
const pickupCode = computed(() => order.value?.pickup_code || null);
const pickupQrUrl = computed(() => {
    if (!pickupCode.value) {
        return null;
    }
    return `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(pickupCode.value)}`;
});
const showPickupQr = computed(() =>
    order.value?.fulfillment_method === 'pickup' && order.value?.fulfillment_status === 'ready_for_pickup'
);
const canConfirmReceipt = computed(() =>
    order.value?.fulfillment_status === 'completed' && !order.value?.delivery_confirmed_at
);
const stripeEnabled = computed(() => Boolean(props.stripe?.enabled));
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
    && isEditing.value
    && depositDue.value > 0
);
const canPayBalance = computed(() =>
    stripeEnabled.value
    && isEditing.value
    && balanceDue.value > 0
);
const categoryOptions = computed(() => props.categories || []);
const trackingOptions = computed(() => ([
    { value: 'all', label: t('portal_shop.filters.tracking_all') },
    { value: 'none', label: t('portal_shop.filters.tracking_standard') },
    { value: 'lot', label: t('portal_shop.filters.tracking_lot') },
    { value: 'serial', label: t('portal_shop.filters.tracking_serial') },
]));

const search = ref('');
const cart = ref([]);
const orderHydrated = ref(false);
const selectedProduct = ref(null);
const showProductDetails = ref(false);
const showCart = ref(false);
const selectedCategoryId = ref('');
const stockFilter = ref('all');
const trackingFilter = ref('all');

const cartStorageKey = computed(() => {
    const ownerId = props.company?.id || page.props.auth?.account?.owner_id || 'unknown';
    const customerId = props.customer?.id || page.props.auth?.user?.id || 'unknown';
    return `portal_cart_v1_${ownerId}_${customerId}`;
});

const getStorage = () => (typeof window === 'undefined' ? null : window.localStorage);

const readCartStorage = () => {
    const storage = getStorage();
    if (!storage) {
        return [];
    }
    const raw = storage.getItem(cartStorageKey.value);
    if (!raw) {
        return [];
    }
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        return [];
    }
};

const writeCartStorage = (items) => {
    const storage = getStorage();
    if (!storage) {
        return;
    }
    if (!items.length) {
        storage.removeItem(cartStorageKey.value);
        return;
    }
    storage.setItem(cartStorageKey.value, JSON.stringify(items));
};

const resetFilters = () => {
    selectedCategoryId.value = '';
    stockFilter.value = 'all';
    trackingFilter.value = 'all';
};

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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

const filteredProducts = computed(() => {
    const keyword = search.value.trim().toLowerCase();

    return props.products.filter((product) => {
        const matchesSearch = !keyword
            || [product.name, product.sku].filter(Boolean).some((field) =>
                String(field).toLowerCase().includes(keyword)
            );
        const matchesCategory = !selectedCategoryId.value
            || String(product.category_id || '') === String(selectedCategoryId.value);
        const productStock = Number(product.stock || 0);
        const minimumStock = Number(product.minimum_stock || 0);
        const matchesStock = stockFilter.value === 'all'
            || (stockFilter.value === 'out' && productStock <= 0)
            || (stockFilter.value === 'low' && productStock > 0 && minimumStock > 0 && productStock <= minimumStock)
            || (stockFilter.value === 'in' && productStock > 0 && (minimumStock <= 0 || productStock > minimumStock));
        const tracking = product.tracking_type || 'none';
        const matchesTracking = trackingFilter.value === 'all'
            || (trackingFilter.value === 'none' && (tracking === 'none' || tracking === null))
            || trackingFilter.value === tracking;

        return matchesSearch && matchesCategory && matchesStock && matchesTracking;
    });
});

const findCartItem = (productId) => cart.value.find((item) => item.product.id === productId);
const cartQuantity = (productId) => findCartItem(productId)?.quantity || 0;

const addToCart = (product) => {
    if (isLocked.value) {
        return;
    }
    if (!product || product.stock <= 0) {
        return;
    }
    const existing = findCartItem(product.id);
    if (existing) {
        if (existing.quantity < product.stock) {
            existing.quantity += 1;
        }
        return;
    }
    cart.value.push({ product, quantity: 1 });
};

const updateQuantity = (productId, delta) => {
    if (isLocked.value) {
        return;
    }
    const item = findCartItem(productId);
    if (!item) {
        return;
    }
    const next = item.quantity + delta;
    if (next <= 0) {
        cart.value = cart.value.filter((entry) => entry.product.id !== productId);
        return;
    }
    item.quantity = Math.min(next, item.product.stock);
};

const removeItem = (productId) => {
    if (isLocked.value) {
        return;
    }
    cart.value = cart.value.filter((entry) => entry.product.id !== productId);
};

const openProductDetails = (product) => {
    if (!product) {
        return;
    }
    selectedProduct.value = product;
    showProductDetails.value = true;
};

const closeProductDetails = () => {
    showProductDetails.value = false;
    selectedProduct.value = null;
};

const stockMeta = (product) => {
    const stock = Number(product?.stock ?? 0);
    const minimum = Number(product?.minimum_stock ?? 0);

    if (stock <= 0) {
        return { label: t('portal_shop.stock.out'), classes: 'border-red-200 bg-red-50 text-red-700' };
    }
    if (minimum > 0 && stock <= minimum) {
        return { label: t('portal_shop.stock.low'), classes: 'border-amber-200 bg-amber-50 text-amber-700' };
    }
    return { label: t('portal_shop.stock.in'), classes: 'border-emerald-200 bg-emerald-50 text-emerald-700' };
};

const openCart = () => {
    showCart.value = true;
};

const closeCart = () => {
    showCart.value = false;
};

const goToCart = () => {
    closeProductDetails();
    openCart();
};

const initialMethod = computed(() => {
    if (props.fulfillment?.delivery_enabled) {
        return 'delivery';
    }
    return 'pickup';
});

const form = useForm({
    fulfillment_method: initialMethod.value,
    delivery_address: props.customer?.default_address || '',
    delivery_notes: '',
    pickup_notes: '',
    scheduled_for: '',
    customer_notes: '',
    substitution_allowed: true,
    substitution_notes: '',
    items: [],
});
const confirmForm = useForm({
    proof: null,
});

const subtotal = computed(() =>
    cart.value.reduce((sum, entry) => sum + Number(entry.product.price || 0) * entry.quantity, 0)
);
const cartItemCount = computed(() => cart.value.reduce((sum, entry) => sum + entry.quantity, 0));

const taxTotal = computed(() =>
    cart.value.reduce((sum, entry) => {
        const taxRate = Number(entry.product.tax_rate || 0);
        const lineTotal = Number(entry.product.price || 0) * entry.quantity;
        return sum + (taxRate > 0 ? (lineTotal * taxRate) / 100 : 0);
    }, 0)
);

const deliveryFee = computed(() =>
    form.fulfillment_method === 'delivery' ? Number(props.fulfillment?.delivery_fee || 0) : 0
);

const grandTotal = computed(() => subtotal.value + taxTotal.value + deliveryFee.value);

const canCheckout = computed(() => {
    if (!cart.value.length) {
        return false;
    }
    if (form.fulfillment_method === 'delivery') {
        return String(form.delivery_address || '').trim().length > 0;
    }
    return true;
});

const selectMethod = (method) => {
    if (isLocked.value) {
        return;
    }
    form.fulfillment_method = method;
};

const toDateTimeLocal = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const pad = (number) => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

watch(
    () => [order.value, props.products],
    () => {
        if (!order.value || orderHydrated.value || !props.products.length) {
            return;
        }

        const productMap = new Map(props.products.map((product) => [product.id, product]));
        const nextCart = [];

        (order.value.items || []).forEach((item) => {
            const product = productMap.get(item.product_id);
            if (!product) {
                return;
            }
            nextCart.push({
                product,
                quantity: Number(item.quantity || 0) || 1,
            });
        });

        cart.value = nextCart;
        form.fulfillment_method = order.value.fulfillment_method || initialMethod.value;
        form.delivery_address = order.value.delivery_address || props.customer?.default_address || '';
        form.delivery_notes = order.value.delivery_notes || '';
        form.pickup_notes = order.value.pickup_notes || '';
        form.scheduled_for = toDateTimeLocal(order.value.scheduled_for);
        form.customer_notes = order.value.customer_notes || '';
        form.substitution_allowed = order.value.substitution_allowed !== false;
        form.substitution_notes = order.value.substitution_notes || '';
        orderHydrated.value = true;
    },
    { immediate: true }
);

watch(
    () => [props.products, isEditing.value],
    () => {
        if (isEditing.value || cartRestored.value || !props.products.length) {
            return;
        }
        const storedItems = readCartStorage();
        if (!storedItems.length) {
            cartRestored.value = true;
            return;
        }
        const productMap = new Map(props.products.map((product) => [product.id, product]));
        const restored = storedItems
            .map((item) => {
                const product = productMap.get(item.product_id);
                if (!product) {
                    return null;
                }
                const quantity = Math.min(Number(item.quantity || 0), Number(product.stock || 0));
                if (quantity <= 0) {
                    return null;
                }
                return { product, quantity };
            })
            .filter(Boolean);

        if (restored.length) {
            cart.value = restored;
        }
        cartRestored.value = true;
    },
    { immediate: true }
);

watch(
    cart,
    () => {
        if (isEditing.value) {
            return;
        }
        const payload = cart.value.map((entry) => ({
            product_id: entry.product.id,
            quantity: entry.quantity,
        }));
        writeCartStorage(payload);
    },
    { deep: true }
);

const submitOrder = () => {
    if (isLocked.value) {
        return;
    }
    form.items = cart.value.map((entry) => ({
        product_id: entry.product.id,
        quantity: entry.quantity,
    }));

    if (isEditing.value && order.value?.id) {
        form.put(route('portal.orders.update', order.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                showCart.value = false;
            },
        });
        return;
    }

    form.post(route('portal.orders.store'), {
        preserveScroll: true,
        onSuccess: () => {
            cart.value = [];
            form.delivery_notes = '';
            form.pickup_notes = '';
            form.scheduled_for = '';
            showCart.value = false;
        },
    });
};

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

const cancelOrder = () => {
    if (!isEditing.value || !order.value?.id || isLocked.value) {
        return;
    }
    if (!confirm(t('portal_shop.actions.cancel_confirm'))) {
        return;
    }
    form.delete(route('portal.orders.destroy', order.value.id), {
        preserveScroll: true,
    });
};

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
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="pageTitle" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="h-12 w-12 overflow-hidden rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        <img
                            v-if="company?.logo_url"
                            :src="company.logo_url"
                            :alt="company?.name || $t('portal_shop.header.logo_alt')"
                            class="h-full w-full object-cover"
                            loading="lazy"
                            decoding="async"
                        >
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.header.section') }}</p>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ headerTitle }}
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ headerSubtitle }}
                        </p>
                    </div>
                </div>
                <Link :href="route('dashboard')" class="text-xs font-semibold text-green-700 hover:underline dark:text-green-400">
                    {{ $t('portal_shop.actions.back_to_dashboard') }}
                </Link>
            </div>

            <div v-if="flashSuccess" class="rounded-sm border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ flashSuccess }}
            </div>
            <div v-if="flashError" class="rounded-sm border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ flashError }}
            </div>
            <div v-if="isLocked" class="rounded-sm border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                {{ $t('portal_shop.locked_notice') }}
            </div>
            <div v-if="isEditing" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.status.order_status') }}</p>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ orderStatusLabel }}
                        </p>
                        <p v-if="order?.delivery_confirmed_at" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('portal_shop.status.confirmed_at', { date: formatDateTime(order.delivery_confirmed_at) }) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full border border-stone-200 bg-stone-50 px-2 py-1 text-xs font-semibold text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ $t('portal_shop.status.payment', { status: paymentStatusLabel }) }}
                        </span>
                    </div>
                </div>
                <div v-if="depositAmount > 0" class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                    <div class="flex items-center justify-between">
                        <span>{{ $t('portal_shop.payment.deposit_required') }}</span>
                        <span class="font-semibold">{{ formatCurrency(depositAmount) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                        <span>{{ $t('portal_shop.payment.amount_paid') }}</span>
                        <span>{{ formatCurrency(amountPaid) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                        <span>{{ $t('portal_shop.payment.balance_due') }}</span>
                        <span>{{ formatCurrency(balanceDue) }}</span>
                    </div>
                    <div v-if="paymentError" class="text-xs text-red-600">
                        {{ paymentError }}
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="canPayDeposit"
                            type="button"
                            class="rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="paymentProcessing"
                            @click="startPayment('deposit')"
                        >
                            {{ paymentProcessing ? $t('portal_shop.payment.processing') : $t('portal_shop.payment.pay_deposit') }}
                        </button>
                        <button
                            v-if="canPayBalance"
                            type="button"
                            class="rounded-sm bg-stone-800 px-3 py-2 text-xs font-semibold text-white hover:bg-stone-900 disabled:opacity-60"
                            :disabled="paymentProcessing"
                            @click="startPayment('balance')"
                        >
                            {{ paymentProcessing ? $t('portal_shop.payment.processing') : $t('portal_shop.payment.pay_balance') }}
                        </button>
                    </div>
                </div>
                <div v-if="order?.delivery_proof_url" class="mt-3">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.status.delivery_photo') }}</p>
                    <img
                        :src="order.delivery_proof_url"
                        :alt="$t('portal_shop.status.delivery_photo_alt')"
                        class="mt-2 h-40 w-full rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                        loading="lazy"
                        decoding="async"
                    >
                </div>
                <div v-if="canConfirmReceipt" class="mt-4 rounded-sm border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                    <p class="font-semibold">{{ $t('portal_shop.confirm_receipt.title') }}</p>
                    <p class="text-xs text-emerald-700">{{ $t('portal_shop.confirm_receipt.subtitle') }}</p>
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
                            {{ $t('portal_shop.confirm_receipt.action') }}
                        </button>
                    </div>
                    <div v-if="confirmForm.errors.proof" class="mt-1 text-xs text-red-600">
                        {{ confirmForm.errors.proof }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[260px_1fr]">
                <aside class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-sm border border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="M3 4h18" />
                                        <path d="M6 10h12" />
                                        <path d="M10 16h4" />
                                    </svg>
                                </span>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('portal_shop.filters.title') }}</h2>
                            </div>
                            <button
                                type="button"
                                class="text-xs font-semibold text-stone-500 hover:text-stone-700 dark:text-neutral-400"
                                @click="resetFilters"
                            >
                                {{ $t('portal_shop.actions.reset_filters') }}
                            </button>
                        </div>
                        <div class="mt-4 space-y-4 text-sm">
                            <div>
                                <FloatingSelect
                                    v-model="selectedCategoryId"
                                    :label="$t('portal_shop.filters.category_label')"
                                    :options="categoryOptions"
                                    :placeholder="$t('portal_shop.filters.category_all')"
                                />
                            </div>
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.filters.availability_label') }}</label>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                                    <button
                                        type="button"
                                        class="rounded-sm border px-2 py-1.5 font-semibold"
                                        :class="stockFilter === 'all'
                                            ? 'border-green-500 bg-green-50 text-green-700'
                                            : 'border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                        @click="stockFilter = 'all'"
                                    >
                                        {{ $t('portal_shop.filters.availability_all') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-sm border px-2 py-1.5 font-semibold"
                                        :class="stockFilter === 'in'
                                            ? 'border-green-500 bg-green-50 text-green-700'
                                            : 'border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                        @click="stockFilter = 'in'"
                                    >
                                        {{ $t('portal_shop.filters.availability_in') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-sm border px-2 py-1.5 font-semibold"
                                        :class="stockFilter === 'low'
                                            ? 'border-amber-500 bg-amber-50 text-amber-700'
                                            : 'border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                        @click="stockFilter = 'low'"
                                    >
                                        {{ $t('portal_shop.filters.availability_low') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-sm border px-2 py-1.5 font-semibold"
                                        :class="stockFilter === 'out'
                                            ? 'border-red-500 bg-red-50 text-red-700'
                                            : 'border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                        @click="stockFilter = 'out'"
                                    >
                                        {{ $t('portal_shop.filters.availability_out') }}
                                    </button>
                                </div>
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="trackingFilter"
                                    :label="$t('portal_shop.filters.tracking_label')"
                                    :options="trackingOptions"
                                />
                            </div>
                        </div>
                    </div>
                </aside>
                <div class="space-y-4">
                    <div v-if="timeline.length || showPickupQr" class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <div v-if="timeline.length" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('portal_shop.timeline.title') }}</h2>
                            <div class="mt-3 space-y-3">
                                <div v-for="event in timeline" :key="event.id" class="flex items-start gap-3 text-sm">
                                    <span class="mt-1 h-2.5 w-2.5 rounded-full bg-green-500"></span>
                                    <div class="flex-1">
                                        <p class="font-medium text-stone-800 dark:text-neutral-100">
                                            {{ event.label }}
                                        </p>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ formatDateTime(event.created_at) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="showPickupQr && pickupCode" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('portal_shop.timeline.pickup_qr_title') }}</h2>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('portal_shop.timeline.pickup_qr_note') }}
                            </p>
                            <div class="mt-3 flex flex-col items-center gap-2">
                                <img
                                    v-if="pickupQrUrl"
                                    :src="pickupQrUrl"
                                    :alt="$t('portal_shop.timeline.pickup_qr_alt', { code: pickupCode })"
                                    class="h-40 w-40 rounded-sm border border-stone-200 bg-white object-contain p-2 dark:border-neutral-700"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ $t('portal_shop.timeline.pickup_code', { code: pickupCode }) }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="relative flex-1">
                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                                <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8" />
                                    <path d="m21 21-4.3-4.3" />
                                </svg>
                            </div>
                            <input
                                v-model="search"
                                type="text"
                                class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                                :placeholder="$t('portal_shop.search.placeholder')"
                            >
                        </div>
                        <div class="flex items-center gap-3 text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ $t('portal_shop.search.results', { count: filteredProducts.length }) }}</span>
                            <button
                                type="button"
                                class="relative inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 transition hover:-translate-y-0.5 hover:bg-stone-50 hover:shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="openCart"
                            >
                                <span class="relative flex h-5 w-5 items-center justify-center">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <circle cx="8" cy="21" r="1" />
                                        <circle cx="19" cy="21" r="1" />
                                        <path d="M2.05 2.05h2l2.76 12.2a2 2 0 0 0 2 1.6h9.72a2 2 0 0 0 2-1.6l1.38-7.6H6.1" />
                                    </svg>
                                    <span v-if="cartItemCount" class="absolute -right-1 -top-1 flex h-3 w-3">
                                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500"></span>
                                    </span>
                                </span>
                                <span>{{ $t('portal_shop.cart.label', { count: cartItemCount }) }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="!filteredProducts.length" class="rounded-sm border border-stone-200 bg-white p-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                        {{ $t('portal_shop.empty.no_products') }}
                    </div>
                    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="(product, index) in filteredProducts"
                            :key="product.id"
                            class="shop-card group relative flex h-full cursor-pointer flex-col rounded-sm border border-stone-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900"
                            :style="{ animationDelay: `${Math.min(index, 10) * 40}ms` }"
                            @click="openProductDetails(product)"
                        >
                            <span
                                class="absolute right-3 top-3 inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                :class="stockMeta(product).classes"
                            >
                                {{ stockMeta(product).label }}
                            </span>
                            <div class="relative h-40 w-full overflow-hidden rounded-sm border border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800">
                                <img
                                    v-if="product.image_url || product.image"
                                    :src="product.image_url || product.image"
                                    :alt="product.name"
                                    class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <div v-else class="flex h-full w-full items-center justify-center text-stone-400">
                                    <svg class="size-10" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                                        <path d="m21 15-5-5L5 21" />
                                        <path d="M14 10h.01" />
                                    </svg>
                                </div>
                            </div>
                            <div class="mt-3 flex-1 space-y-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ product.name }}</p>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">{{ product.sku || $t('portal_shop.product.sku_fallback') }}</p>
                                    </div>
                                    <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(product.price) }}
                                    </span>
                                </div>
                                <p v-if="product.description" class="max-h-10 overflow-hidden text-xs text-stone-500 dark:text-neutral-400">
                                    {{ product.description }}
                                </p>
                                <p v-else class="text-xs text-stone-400">{{ $t('portal_shop.empty.no_description') }}</p>
                            </div>
                            <div class="mt-3 flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                <span>
                                    {{ $t('portal_shop.labels.stock', { count: product.stock }) }}
                                    <span v-if="product.unit">- {{ product.unit }}</span>
                                </span>
                                <div class="flex items-center gap-2">
                                    <template v-if="cartQuantity(product.id) > 0">
                                        <button
                                            type="button"
                                            class="h-7 w-7 rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200"
                                            :disabled="isLocked"
                                            @click.stop="updateQuantity(product.id, -1)"
                                        >
                                            -
                                        </button>
                                        <span class="text-xs font-semibold text-stone-700 dark:text-neutral-100">
                                            {{ cartQuantity(product.id) }}
                                        </span>
                                        <button
                                            type="button"
                                            class="h-7 w-7 rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200"
                                            :disabled="isLocked || cartQuantity(product.id) >= product.stock"
                                            @click.stop="updateQuantity(product.id, 1)"
                                        >
                                            +
                                        </button>
                                    </template>
                                    <button
                                        v-else
                                        type="button"
                                        class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                        :disabled="product.stock <= 0 || isLocked"
                                        @click.stop="addToCart(product)"
                                    >
                                        {{ $t('portal_shop.product.add') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showCart" @close="closeCart" maxWidth="2xl">
            <div class="flex items-start justify-between border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                <div>
                    <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.cart.title') }}</p>
                    <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                        {{ isEditing ? $t('portal_shop.cart.header_order') : $t('portal_shop.cart.header_cart') }}
                    </h2>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('portal_shop.cart.items_count', { count: cartItemCount }) }}
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="closeCart"
                >
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18" />
                        <path d="m6 6 12 12" />
                    </svg>
                </button>
            </div>
            <div class="space-y-4 p-4">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('portal_shop.cart.items_title') }}</h3>
                        <span class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('portal_shop.cart.items_count', { count: cartItemCount }) }}
                        </span>
                    </div>
                    <div v-if="!cart.length" class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('portal_shop.cart.items_empty') }}
                    </div>
                    <div v-else class="mt-3 space-y-3">
                        <div v-for="entry in cart" :key="entry.product.id" class="flex items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium text-stone-800 dark:text-neutral-100">{{ entry.product.name }}</p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatCurrency(entry.product.price) }} x {{ entry.quantity }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button"
                                    class="h-7 w-7 rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200"
                                    :disabled="isLocked"
                                    @click="updateQuantity(entry.product.id, -1)">
                                    -
                                </button>
                                <span class="text-sm font-semibold text-stone-700 dark:text-neutral-100">{{ entry.quantity }}</span>
                                <button type="button"
                                    class="h-7 w-7 rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200"
                                    :disabled="isLocked"
                                    @click="updateQuantity(entry.product.id, 1)">
                                    +
                                </button>
                                <button type="button"
                                    class="text-xs text-red-600 hover:underline disabled:opacity-50"
                                    :disabled="isLocked"
                                    @click="removeItem(entry.product.id)">
                                    {{ $t('portal_shop.cart.remove') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="cart.length" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('portal_shop.fulfillment.title') }}</h2>
                    <div class="mt-3 grid grid-cols-1 gap-2">
                        <button
                            v-if="fulfillment?.delivery_enabled"
                            type="button"
                            class="flex items-center justify-between rounded-sm border px-3 py-2 text-sm"
                            :class="form.fulfillment_method === 'delivery'
                                ? 'border-green-500 bg-green-50 text-green-700'
                                : 'border-stone-200 text-stone-700 dark:border-neutral-700 dark:text-neutral-200'"
                            @click="selectMethod('delivery')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <rect x="1" y="3" width="15" height="13" />
                                    <path d="M16 8h4l3 3v5h-7" />
                                    <circle cx="5.5" cy="18.5" r="1.5" />
                                    <circle cx="18.5" cy="18.5" r="1.5" />
                                </svg>
                                {{ $t('portal_shop.fulfillment.delivery') }}
                            </span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ deliveryFee
                                    ? $t('portal_shop.fulfillment.delivery_fee', { amount: formatCurrency(deliveryFee) })
                                    : $t('portal_shop.fulfillment.delivery_free') }}
                            </span>
                        </button>
                        <button
                            v-if="fulfillment?.pickup_enabled"
                            type="button"
                            class="flex items-center justify-between rounded-sm border px-3 py-2 text-sm"
                            :class="form.fulfillment_method === 'pickup'
                                ? 'border-green-500 bg-green-50 text-green-700'
                                : 'border-stone-200 text-stone-700 dark:border-neutral-700 dark:text-neutral-200'"
                            @click="selectMethod('pickup')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M3 9h18" />
                                    <path d="M3 9l2-4h14l2 4" />
                                    <path d="M7 9v9" />
                                    <path d="M17 9v9" />
                                    <path d="M9 18h6" />
                                </svg>
                                {{ $t('portal_shop.fulfillment.pickup') }}
                            </span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('portal_shop.fulfillment.pickup_ready', { minutes: fulfillment?.prep_time_minutes || 30 }) }}
                            </span>
                        </button>
                    </div>

                    <div v-if="form.fulfillment_method === 'delivery'" class="mt-3 space-y-2 text-sm">
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.fulfillment.delivery_address') }}</label>
                        <textarea v-model="form.delivery_address"
                            class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            rows="2" />
                        <div v-if="form.errors.delivery_address" class="text-xs text-red-600">
                            {{ form.errors.delivery_address }}
                        </div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.fulfillment.delivery_instructions') }}</label>
                        <textarea v-model="form.delivery_notes"
                            class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            rows="2" />
                        <p v-if="fulfillment?.delivery_zone" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('portal_shop.fulfillment.delivery_zone', { zone: fulfillment.delivery_zone }) }}
                        </p>
                    </div>

                    <div v-else class="mt-3 space-y-2 text-sm">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.fulfillment.pickup_address_label') }}</p>
                        <p class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                            {{ fulfillment?.pickup_address || $t('portal_shop.fulfillment.pickup_address_fallback') }}
                        </p>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.fulfillment.pickup_notes') }}</label>
                        <textarea v-model="form.pickup_notes"
                            class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            rows="2" />
                        <p v-if="fulfillment?.pickup_notes" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ fulfillment.pickup_notes }}
                        </p>
                    </div>

                    <div class="mt-3">
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.fulfillment.scheduled') }}</label>
                        <input type="datetime-local" v-model="form.scheduled_for" :disabled="isLocked"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                    </div>
                </div>

                <div v-if="cart.length" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('portal_shop.notes.title') }}</h2>
                    <div class="mt-3 space-y-3 text-sm">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.notes.customer_notes') }}</label>
                            <textarea
                                v-model="form.customer_notes"
                                rows="2"
                                :disabled="isLocked"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <input type="checkbox" v-model="form.substitution_allowed" :disabled="isLocked" />
                            <span>{{ $t('portal_shop.notes.substitution_allowed') }}</span>
                        </label>
                        <div v-if="form.substitution_allowed">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.notes.substitution_notes') }}</label>
                            <textarea
                                v-model="form.substitution_notes"
                                rows="2"
                                :disabled="isLocked"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            />
                        </div>
                    </div>
                </div>

                <div v-if="cart.length" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('portal_shop.summary.title') }}</h2>
                    <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <div class="flex items-center justify-between">
                            <span>{{ $t('portal_shop.summary.subtotal') }}</span>
                            <span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ $t('portal_shop.summary.taxes') }}</span>
                            <span class="font-medium">{{ formatCurrency(taxTotal) }}</span>
                        </div>
                        <div v-if="deliveryFee" class="flex items-center justify-between">
                            <span>{{ $t('portal_shop.summary.delivery') }}</span>
                            <span class="font-medium">{{ formatCurrency(deliveryFee) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                            <span class="font-semibold">{{ $t('portal_shop.summary.total') }}</span>
                            <span class="font-semibold">{{ formatCurrency(grandTotal) }}</span>
                        </div>
                    </div>
                    <div v-if="form.errors.items" class="mt-2 text-xs text-red-600">
                        {{ form.errors.items }}
                    </div>
                    <button type="button"
                        class="mt-4 w-full rounded-sm bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                        :disabled="!canCheckout || form.processing || isLocked"
                        @click="submitOrder">
                        {{ isEditing ? $t('portal_shop.summary.checkout_update') : $t('portal_shop.summary.checkout_create') }}
                    </button>
                    <button
                        v-if="isEditing && canEditOrder"
                        type="button"
                        class="mt-2 w-full rounded-sm border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50"
                        @click="cancelOrder"
                    >
                        {{ $t('portal_shop.actions.cancel_order') }}
                    </button>
                </div>
            </div>
        </Modal>

        <Modal :show="showProductDetails" @close="closeProductDetails" maxWidth="2xl">
            <div v-if="selectedProduct" class="flex items-start justify-between border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                <div>
                    <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.product.label') }}</p>
                    <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ selectedProduct.name }}</h2>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ selectedProduct.sku || $t('portal_shop.product.sku_fallback') }}</p>
                </div>
                <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="closeProductDetails"
                >
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18" />
                        <path d="m6 6 12 12" />
                    </svg>
                </button>
            </div>
            <div v-if="selectedProduct" class="grid gap-4 p-4 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="space-y-3">
                    <div class="relative aspect-[4/3] w-full overflow-hidden rounded-sm border border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800">
                        <img
                            v-if="selectedProduct.image_url || selectedProduct.image"
                            :src="selectedProduct.image_url || selectedProduct.image"
                            :alt="selectedProduct.name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                            decoding="async"
                        >
                        <div v-else class="flex h-full w-full items-center justify-center text-stone-400">
                            <svg class="size-12" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                stroke-linejoin="round">
                                <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                                <path d="m21 15-5-5L5 21" />
                                <path d="M14 10h.01" />
                            </svg>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span
                            class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                            :class="stockMeta(selectedProduct).classes"
                        >
                            {{ stockMeta(selectedProduct).label }}
                        </span>
                        <span>{{ $t('portal_shop.labels.stock', { count: selectedProduct.stock }) }}</span>
                        <span v-if="selectedProduct.unit">- {{ selectedProduct.unit }}</span>
                    </div>
                </div>
                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <span class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.product.price_label') }}</span>
                            <span class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(selectedProduct.price) }}
                            </span>
                        </div>
                        <div class="mt-2 space-y-1 text-xs text-stone-500 dark:text-neutral-400">
                            <div class="flex items-center justify-between">
                                <span>{{ $t('portal_shop.product.supplier') }}</span>
                                <span class="font-medium text-stone-700 dark:text-neutral-200">
                                    {{ selectedProduct.supplier_name || '-' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('portal_shop.product.barcode') }}</span>
                                <span class="font-medium text-stone-700 dark:text-neutral-200">
                                    {{ selectedProduct.barcode || '-' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('portal_shop.product.min_stock') }}</span>
                                <span class="font-medium text-stone-700 dark:text-neutral-200">
                                    {{ selectedProduct.minimum_stock ?? '-' }}
                                </span>
                            </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between">
                            <span class="text-xs font-semibold text-stone-600 dark:text-neutral-300">{{ $t('portal_shop.product.quantity') }}</span>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="h-8 w-8 rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200"
                                    :disabled="isLocked || cartQuantity(selectedProduct.id) <= 0"
                                    @click="updateQuantity(selectedProduct.id, -1)"
                                >
                                    -
                                </button>
                                <span class="text-sm font-semibold text-stone-700 dark:text-neutral-100">
                                    {{ cartQuantity(selectedProduct.id) }}
                                </span>
                                <button
                                    type="button"
                                    class="h-8 w-8 rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200"
                                    :disabled="isLocked || cartQuantity(selectedProduct.id) >= selectedProduct.stock"
                                    @click="updateQuantity(selectedProduct.id, 1)"
                                >
                                    +
                                </button>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="mt-3 w-full rounded-sm bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="isLocked || selectedProduct.stock <= 0"
                            @click="addToCart(selectedProduct)"
                        >
                            {{ cartQuantity(selectedProduct.id) > 0
                                ? $t('portal_shop.product.add_another')
                                : $t('portal_shop.product.add_to_cart') }}
                        </button>
                        <button
                            v-if="cartItemCount"
                            type="button"
                            class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="goToCart"
                        >
                            {{ $t('portal_shop.product.go_to_cart') }}
                        </button>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('portal_shop.product.description') }}</p>
                        <p class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                            {{ selectedProduct.description || $t('portal_shop.empty.product_description') }}
                        </p>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<style scoped>
.shop-card {
    animation: fadeUp 0.35s ease both;
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .shop-card {
        animation: none;
    }
}
</style>
