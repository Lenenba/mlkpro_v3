<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InputError from '@/Components/InputError.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';

const props = defineProps({
    sale: {
        type: Object,
        required: true,
    },
    customers: {
        type: Array,
        default: () => [],
    },
    products: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const form = useForm({
    customer_id: props.sale.customer_id || '',
    status: props.sale.status || 'draft',
    fulfillment_status: props.sale.fulfillment_status || null,
    scheduled_for: '',
    notes: props.sale.notes || '',
    items: (props.sale.items || []).map((item) => ({
        product_id: item.product_id,
        quantity: item.quantity,
        price: item.price,
        description: item.description || item.product?.name || '',
    })),
});

const localCustomers = ref([...props.customers]);
const customerOptions = computed(() =>
    localCustomers.value.map((customer) => ({
        value: customer.id,
        label: customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() || customer.email,
    }))
);
const statusOptions = computed(() => ([
    { value: 'draft', label: t('sales.status.draft') },
    { value: 'pending', label: t('sales.status.pending') },
    { value: 'paid', label: t('sales.status.paid') },
    { value: 'canceled', label: t('sales.status.canceled') },
]));
const selectedCustomer = computed(() =>
    localCustomers.value.find((customer) => customer.id === form.customer_id) || null
);

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

form.scheduled_for = toDateTimeLocal(props.sale.scheduled_for);

const fulfillmentMethod = computed(() => props.sale.fulfillment_method || null);
const fulfillmentOptions = computed(() => {
    if (!fulfillmentMethod.value) {
        return [];
    }

    if (fulfillmentMethod.value === 'pickup') {
        return [
            { value: 'pending', label: t('sales.fulfillment.pending') },
            { value: 'preparing', label: t('sales.fulfillment.preparing') },
            { value: 'ready_for_pickup', label: t('sales.fulfillment.ready_for_pickup') },
            { value: 'completed', label: t('sales.fulfillment.completed') },
        ];
    }

    return [
        { value: 'pending', label: t('sales.fulfillment.pending') },
        { value: 'preparing', label: t('sales.fulfillment.preparing') },
        { value: 'out_for_delivery', label: t('sales.fulfillment.out_for_delivery') },
        { value: 'completed', label: t('sales.fulfillment.completed') },
    ];
});

const fulfillmentMethodLabel = computed(() => {
    if (fulfillmentMethod.value === 'delivery') {
        return t('sales.fulfillment.method.delivery');
    }
    if (fulfillmentMethod.value === 'pickup') {
        return t('sales.fulfillment.method.pickup');
    }
    return null;
});

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

const discountRate = computed(() => {
    if (selectedCustomer.value?.discount_rate !== undefined && selectedCustomer.value?.discount_rate !== null) {
        return Number(selectedCustomer.value.discount_rate || 0);
    }
    return Number(props.sale.discount_rate || 0);
});
const discountTotal = computed(() => subtotal.value * (discountRate.value / 100));
const discountedTaxTotal = computed(() => taxTotal.value * (1 - discountRate.value / 100));
const total = computed(() =>
    Math.max(0, subtotal.value - discountTotal.value) + discountedTaxTotal.value
);

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const submit = () => {
    form.fulfillment_status = form.fulfillment_status || null;
    form.put(route('sales.update', props.sale.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('sales.edit.meta_title')" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('sales.edit.title', { number: sale.number || $t('sales.table.sale_label', { id: sale.id }) }) }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('sales.edit.subtitle') }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('sales.show', sale.id)"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        {{ $t('sales.edit.view_sale') }}
                    </Link>
                    <Link
                        :href="route('sales.index')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        {{ $t('sales.actions.back_to_sales') }}
                    </Link>
                </div>
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
                                </div>
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="form.status"
                                    :label="$t('sales.form.status_label')"
                                    :options="statusOptions"
                                />
                                <InputError class="mt-1" :message="form.errors.status" />
                            </div>
                            <div v-if="fulfillmentOptions.length">
                                <FloatingSelect
                                    v-model="form.fulfillment_status"
                                    :label="$t('sales.edit.fulfillment_status_label', { method: fulfillmentMethodLabel || $t('sales.fulfillment.method.delivery') })"
                                    :options="fulfillmentOptions"
                                    :placeholder="$t('sales.edit.fulfillment_placeholder')"
                                />
                                <InputError class="mt-1" :message="form.errors.fulfillment_status" />
                            </div>
                            <div>
                                <DateTimePicker
                                    v-model="form.scheduled_for"
                                    :label="$t('sales.edit.schedule_label')"
                                />
                                <InputError class="mt-1" :message="form.errors.scheduled_for" />
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
                            <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                                <span class="font-semibold">{{ $t('sales.summary.total') }}</span>
                                <span class="font-semibold">{{ formatCurrency(total) }}</span>
                            </div>
                        </div>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing || !form.items.length"
                        class="w-full rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                    >
                        {{ $t('sales.edit.update') }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
