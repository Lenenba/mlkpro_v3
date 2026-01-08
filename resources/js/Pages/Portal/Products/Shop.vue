<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

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
    fulfillment: {
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

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

const order = computed(() => props.order || null);
const isEditing = computed(() => Boolean(order.value?.id));
const canEditOrder = computed(() => {
    if (!isEditing.value) {
        return true;
    }
    return order.value?.can_edit !== false;
});
const isLocked = computed(() => isEditing.value && !canEditOrder.value);
const pageTitle = computed(() => (isEditing.value ? 'Modifier la commande' : 'Commander'));
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

const search = ref('');
const cart = ref([]);
const orderHydrated = ref(false);

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
    if (!keyword) {
        return props.products;
    }
    return props.products.filter((product) =>
        [product.name, product.sku].filter(Boolean).some((field) => String(field).toLowerCase().includes(keyword))
    );
});

const findCartItem = (productId) => cart.value.find((item) => item.product.id === productId);

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

const subtotal = computed(() =>
    cart.value.reduce((sum, entry) => sum + Number(entry.product.price || 0) * entry.quantity, 0)
);

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
        },
    });
};

const cancelOrder = () => {
    if (!isEditing.value || !order.value?.id || isLocked.value) {
        return;
    }
    if (!confirm('Annuler cette commande ?')) {
        return;
    }
    form.delete(route('portal.orders.destroy', order.value.id), {
        preserveScroll: true,
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
                            :alt="company?.name || 'Boutique'"
                            class="h-full w-full object-cover"
                        >
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Boutique</p>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            <span v-if="isEditing">
                                Modifier {{ order?.number || `Commande #${order?.id || ''}` }}
                            </span>
                            <span v-else>
                                Commander chez {{ company?.name || 'nos partenaires' }}
                            </span>
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            <span v-if="isEditing">Mettez a jour vos articles avant la livraison.</span>
                            <span v-else>Choisissez vos produits, livraison ou retrait rapide.</span>
                        </p>
                    </div>
                </div>
                <Link :href="route('dashboard')" class="text-xs font-semibold text-green-700 hover:underline dark:text-green-400">
                    Retour au tableau de bord
                </Link>
            </div>

            <div v-if="flashSuccess" class="rounded-sm border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ flashSuccess }}
            </div>
            <div v-if="flashError" class="rounded-sm border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ flashError }}
            </div>
            <div v-if="isLocked" class="rounded-sm border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                Cette commande est deja en livraison ou finalisee. Les modifications sont bloquees.
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_1fr]">
                <div class="space-y-4">
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
                                placeholder="Rechercher un produit"
                            >
                        </div>
                        <span class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ filteredProducts.length }} produits
                        </span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="product in filteredProducts"
                            :key="product.id"
                            class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                        >
                            <div class="flex items-start gap-3">
                                <img
                                    :src="product.image_url || product.image"
                                    :alt="product.name"
                                    class="h-14 w-14 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                                >
                                <div class="flex-1 space-y-1">
                                    <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ product.name }}</p>
                                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ product.sku || 'SKU -' }}</p>
                                    <div class="flex items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                        <span>{{ formatCurrency(product.price) }}</span>
                                        <span v-if="product.unit">/ {{ product.unit }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                <span :class="product.stock <= 0 ? 'text-red-600' : ''">
                                    Stock {{ product.stock }}
                                </span>
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                    :disabled="product.stock <= 0 || isLocked"
                                    @click="addToCart(product)"
                                >
                                    Ajouter
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div v-if="timeline.length" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Suivi de commande</h2>
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
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">QR de retrait</h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            Montrez ce QR code au comptoir pour confirmer le retrait.
                        </p>
                        <div class="mt-3 flex flex-col items-center gap-2">
                            <img
                                v-if="pickupQrUrl"
                                :src="pickupQrUrl"
                                :alt="`QR ${pickupCode}`"
                                class="h-40 w-40 rounded-sm border border-stone-200 bg-white object-contain p-2 dark:border-neutral-700"
                            >
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                Code: {{ pickupCode }}
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ isEditing ? 'Votre commande' : 'Votre panier' }}
                            </h2>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">{{ cart.length }} articles</span>
                        </div>
                        <div v-if="!cart.length" class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            Ajoutez des produits pour commencer.
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
                                        Supprimer
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Livraison ou retrait</h2>
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
                                <span>Livraison</span>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ deliveryFee ? `Frais ${formatCurrency(deliveryFee)}` : 'Gratuite' }}
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
                                <span>Retrait rapide</span>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    Pret en {{ fulfillment?.prep_time_minutes || 30 }} min
                                </span>
                            </button>
                        </div>

                        <div v-if="form.fulfillment_method === 'delivery'" class="mt-3 space-y-2 text-sm">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Adresse de livraison</label>
                            <textarea v-model="form.delivery_address"
                                class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                rows="2" />
                            <div v-if="form.errors.delivery_address" class="text-xs text-red-600">
                                {{ form.errors.delivery_address }}
                            </div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Instructions (optionnel)</label>
                            <textarea v-model="form.delivery_notes"
                                class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                rows="2" />
                            <p v-if="fulfillment?.delivery_zone" class="text-xs text-stone-500 dark:text-neutral-400">
                                Zone: {{ fulfillment.delivery_zone }}
                            </p>
                        </div>

                        <div v-else class="mt-3 space-y-2 text-sm">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Adresse de retrait</p>
                            <p class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                {{ fulfillment?.pickup_address || 'Retrait en magasin' }}
                            </p>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Notes (optionnel)</label>
                            <textarea v-model="form.pickup_notes"
                                class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                rows="2" />
                            <p v-if="fulfillment?.pickup_notes" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ fulfillment.pickup_notes }}
                            </p>
                        </div>

                        <div class="mt-3">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Horaire souhaite (optionnel)</label>
                            <input type="datetime-local" v-model="form.scheduled_for" :disabled="isLocked"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Notes & substitutions</h2>
                        <div class="mt-3 space-y-3 text-sm">
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">Notes pour l epicerie</label>
                                <textarea
                                    v-model="form.customer_notes"
                                    rows="2"
                                    :disabled="isLocked"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                />
                            </div>
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <input type="checkbox" v-model="form.substitution_allowed" :disabled="isLocked" />
                                <span>Autoriser les substitutions</span>
                            </label>
                            <div v-if="form.substitution_allowed">
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">Preferences de substitution</label>
                                <textarea
                                    v-model="form.substitution_notes"
                                    rows="2"
                                    :disabled="isLocked"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Resume</h2>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div class="flex items-center justify-between">
                                <span>Sous-total</span>
                                <span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Taxes</span>
                                <span class="font-medium">{{ formatCurrency(taxTotal) }}</span>
                            </div>
                            <div v-if="deliveryFee" class="flex items-center justify-between">
                                <span>Livraison</span>
                                <span class="font-medium">{{ formatCurrency(deliveryFee) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                                <span class="font-semibold">Total</span>
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
                            {{ isEditing ? 'Mettre a jour la commande' : 'Commander maintenant' }}
                        </button>
                        <button
                            v-if="isEditing && canEditOrder"
                            type="button"
                            class="mt-2 w-full rounded-sm border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50"
                            @click="cancelOrder"
                        >
                            Annuler la commande
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
