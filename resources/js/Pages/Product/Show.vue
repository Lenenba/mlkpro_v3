<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/UI/Card.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        required: true,
    },
});

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const trackingLabel = (product) => {
    if (product?.tracking_type === 'lot') {
        return 'Lot';
    }
    if (product?.tracking_type === 'serial') {
        return 'Serial';
    }
    return 'Standard';
};

const inventoryList = computed(() => Array.isArray(props.product?.inventories) ? props.product.inventories : []);
const totalOnHand = computed(() =>
    inventoryList.value.reduce((sum, inventory) => sum + Number(inventory?.on_hand || 0), 0)
);
const totalReserved = computed(() =>
    inventoryList.value.reduce((sum, inventory) => sum + Number(inventory?.reserved || 0), 0)
);
const totalDamaged = computed(() =>
    inventoryList.value.reduce((sum, inventory) => sum + Number(inventory?.damaged || 0), 0)
);
const stockValue = computed(() => {
    const unitValue = Number(props.product?.cost_price || 0) || Number(props.product?.price || 0);
    return totalOnHand.value * unitValue;
});

const lotItems = computed(() => {
    if (!Array.isArray(props.product?.lots)) {
        return [];
    }
    return [...props.product.lots].sort((a, b) => {
        const aDate = a?.expires_at ? new Date(a.expires_at).getTime() : Number.MAX_SAFE_INTEGER;
        const bDate = b?.expires_at ? new Date(b.expires_at).getTime() : Number.MAX_SAFE_INTEGER;
        return aDate - bDate;
    });
});

const lotStatus = (lot) => {
    if (!lot?.expires_at) {
        return 'Active';
    }
    return new Date(lot.expires_at).getTime() < Date.now() ? 'Expired' : 'Active';
};

const alertToneClasses = {
    danger: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300',
    warning: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    info: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
};

const alerts = computed(() => {
    const items = [];
    const onHand = totalOnHand.value;
    const minStock = Number(props.product?.minimum_stock || 0);
    const damaged = totalDamaged.value;
    const reserved = totalReserved.value;

    const today = Date.now();
    const expiringLimit = today + (1000 * 60 * 60 * 24 * 30);
    const expiredCount = lotItems.value.filter((lot) => {
        const date = lot?.expires_at ? new Date(lot.expires_at).getTime() : null;
        return date !== null && date < today;
    }).length;
    const expiringCount = lotItems.value.filter((lot) => {
        const date = lot?.expires_at ? new Date(lot.expires_at).getTime() : null;
        return date !== null && date >= today && date <= expiringLimit;
    }).length;

    if (onHand <= 0) {
        items.push({ label: 'Rupture de stock', tone: 'danger' });
    } else if (minStock > 0 && onHand <= minStock) {
        items.push({ label: 'Stock faible', tone: 'warning' });
    } else {
        items.push({ label: 'Stock OK', tone: 'success' });
    }

    if (damaged > 0) {
        items.push({ label: `Avaries ${formatNumber(damaged)}`, tone: 'danger' });
    }
    if (reserved > 0) {
        items.push({ label: `Reserve ${formatNumber(reserved)}`, tone: 'info' });
    }
    if (expiredCount > 0) {
        items.push({ label: `Lots expires ${formatNumber(expiredCount)}`, tone: 'danger' });
    }
    if (expiringCount > 0) {
        items.push({ label: `Lots bientot expires ${formatNumber(expiringCount)}`, tone: 'warning' });
    }

    return items;
});

const lowStockActive = computed(() => {
    const onHand = totalOnHand.value;
    const minStock = Number(props.product?.minimum_stock || 0);
    if (minStock <= 0) {
        return onHand <= 0;
    }
    return onHand <= minStock;
});

const canRequestSupplier = computed(() =>
    lowStockActive.value && Boolean(props.product?.supplier_email)
);

const requestSupplierStock = () => {
    if (!canRequestSupplier.value) {
        return;
    }
    if (!confirm('Demander un reapprovisionnement au fournisseur ?')) {
        return;
    }
    router.post(route('product.supplier-email', props.product.id), {}, {
        preserveScroll: true,
    });
};

const kpiCards = computed(() => ([
    {
        label: 'En stock',
        value: formatNumber(totalOnHand.value),
        tone: 'success',
        icon: 'box',
    },
    {
        label: 'Valeur stock',
        value: formatCurrency(stockValue.value),
        tone: 'info',
        icon: 'cash',
    },
    {
        label: 'Reserve',
        value: formatNumber(totalReserved.value),
        tone: 'warning',
        icon: 'lock',
    },
    {
        label: 'Avaries',
        value: formatNumber(totalDamaged.value),
        tone: 'danger',
        icon: 'alert',
    },
    {
        label: 'Lots/serials',
        value: formatNumber(lotItems.value.length),
        tone: 'info',
        icon: 'layers',
    },
    {
        label: 'Minimum',
        value: formatNumber(props.product?.minimum_stock || 0),
        tone: 'warning',
        icon: 'thermo',
    },
]));

const galleryImages = computed(() => {
    const items = [];
    const primary = props.product?.image_url || props.product?.image;
    if (primary) {
        items.push(primary);
    }
    if (Array.isArray(props.product?.images)) {
        props.product.images.forEach((image) => {
            const url = image?.url || image?.path;
            if (!url) {
                return;
            }
            if (!items.includes(url)) {
                items.push(url);
            }
        });
    }
    return items;
});

const formatDate = (value) => humanizeDate(value);
</script>

<template>
    <Head :title="product.name || 'Product'" />
    <AuthenticatedLayout>
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <img
                        :src="product.image_url || product.image"
                        :alt="product.name || 'Product'"
                        class="h-16 w-16 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                    />
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ product.name || 'Product' }}
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ product.sku || product.number || 'No SKU' }}
                            <span v-if="product.barcode" class="ml-2">Barcode {{ product.barcode }}</span>
                        </p>
                    </div>
                </div>
                <Link
                    :href="route('product.index')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    Retour aux produits
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_1fr]">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="(card, index) in kpiCards"
                            :key="card.label"
                            class="rise-in rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                            :style="{ animationDelay: `${index * 80}ms` }"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs uppercase text-stone-400">{{ card.label }}</p>
                                    <p class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</p>
                                </div>
                                <span
                                    class="flex h-10 w-10 items-center justify-center rounded-full"
                                    :class="alertToneClasses[card.tone]"
                                >
                                    <svg v-if="card.icon === 'box'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="m7.5 4.27 9 5.15" />
                                        <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
                                        <path d="m3.3 7 8.7 5 8.7-5" />
                                        <path d="M12 22V12" />
                                    </svg>
                                    <svg v-else-if="card.icon === 'cash'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="2" y="5" width="20" height="14" rx="2" />
                                        <path d="M16 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" />
                                        <path d="M6 15h.01" />
                                        <path d="M18 9h.01" />
                                    </svg>
                                    <svg v-else-if="card.icon === 'lock'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="10" rx="2" />
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                    </svg>
                                    <svg v-else-if="card.icon === 'alert'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                        <path d="M12 9v4" />
                                        <path d="M12 17h.01" />
                                    </svg>
                                    <svg v-else-if="card.icon === 'layers'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="m12 2 10 6-10 6L2 8z" />
                                        <path d="m2 14 10 6 10-6" />
                                    </svg>
                                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 4a2 2 0 0 1 2 2v14H8V6a2 2 0 0 1 2-2h4Z" />
                                        <path d="M5 10h14" />
                                    </svg>
                                </span>
                            </div>
                        </div>
                    </div>

                    <Card class="rise-in" :style="{ animationDelay: '120ms' }">
                        <template #title>Alertes</template>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span
                                v-for="alert in alerts"
                                :key="alert.label"
                                class="rounded-full px-2 py-1 font-semibold"
                                :class="alertToneClasses[alert.tone]"
                            >
                                {{ alert.label }}
                            </span>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                :disabled="!canRequestSupplier"
                                @click="requestSupplierStock"
                            >
                                Demander stock fournisseur
                            </button>
                            <span v-if="lowStockActive && !product.supplier_email" class="text-[11px] text-stone-400">
                                Ajoutez un email fournisseur pour envoyer la demande.
                            </span>
                        </div>
                    </Card>

                    <Card v-if="inventoryList.length" class="rise-in" :style="{ animationDelay: '160ms' }">
                        <template #title>Warehouses</template>
                        <div class="space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                            <div v-for="inventory in inventoryList" :key="inventory.id"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                                <div class="flex items-center justify-between">
                                    <div class="font-medium text-stone-700 dark:text-neutral-200">
                                        {{ inventory.warehouse?.name || 'Warehouse' }}
                                    </div>
                                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                                        Bin {{ inventory.bin_location || '-' }}
                                    </span>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <div>On hand: {{ formatNumber(inventory.on_hand) }}</div>
                                    <div>Reserved: {{ formatNumber(inventory.reserved) }}</div>
                                    <div>Damaged: {{ formatNumber(inventory.damaged) }}</div>
                                    <div>Min: {{ formatNumber(inventory.minimum_stock) }}</div>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <Card v-if="lotItems.length" class="rise-in" :style="{ animationDelay: '200ms' }">
                        <template #title>Lots & Serials</template>
                        <div class="space-y-2">
                            <div v-for="lot in lotItems" :key="lot.id"
                                class="rounded-sm border border-stone-200 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-medium text-stone-700 dark:text-neutral-200">
                                        {{ lot.serial_number ? `Serial ${lot.serial_number}` : (lot.lot_number ? `Lot ${lot.lot_number}` : 'Lot') }}
                                    </div>
                                    <span class="rounded-full px-2 py-1 text-[10px] font-semibold"
                                        :class="lotStatus(lot) === 'Expired'
                                            ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300'
                                            : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'">
                                        {{ lotStatus(lot) }}
                                    </span>
                                </div>
                                <div class="mt-2 grid grid-cols-1 gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <div>Warehouse: {{ lot.warehouse?.name || 'Warehouse' }}</div>
                                    <div>Quantity: {{ formatNumber(lot.quantity) }}</div>
                                    <div v-if="lot.received_at">Received {{ formatDate(lot.received_at) }}</div>
                                    <div v-if="lot.expires_at">Expires {{ formatDate(lot.expires_at) }}</div>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <Card v-if="product.description" class="rise-in" :style="{ animationDelay: '240ms' }">
                        <template #title>Description</template>
                        <p class="text-sm text-stone-700 dark:text-neutral-200">{{ product.description }}</p>
                    </Card>
                </div>

                <div class="space-y-4">
                    <Card class="rise-in" :style="{ animationDelay: '80ms' }">
                        <template #title>General</template>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                            <dt class="text-stone-500 dark:text-neutral-400">Status</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ product.is_active ? 'Active' : 'Archived' }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Category</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ product.category?.name || 'Uncategorized' }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Tracking</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ trackingLabel(product) }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Unit</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ product.unit || '-' }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Supplier</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ product.supplier_name || '-' }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Supplier email</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                <a
                                    v-if="product.supplier_email"
                                    class="text-green-700 hover:underline dark:text-green-400"
                                    :href="`mailto:${product.supplier_email}`"
                                >
                                    {{ product.supplier_email }}
                                </a>
                                <span v-else>-</span>
                            </dd>
                        </dl>
                    </Card>

                    <Card class="rise-in" :style="{ animationDelay: '120ms' }">
                        <template #title>Pricing</template>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                            <dt class="text-stone-500 dark:text-neutral-400">Price</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ formatCurrency(product.price) }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Cost</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ formatCurrency(product.cost_price) }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Margin</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ Number(product.margin_percent || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) }}%
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Tax rate</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ Number(product.tax_rate || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) }}%
                            </dd>
                        </dl>
                    </Card>

                    <Card class="rise-in" :style="{ animationDelay: '160ms' }">
                        <template #title>Inventory</template>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                            <dt class="text-stone-500 dark:text-neutral-400">On hand</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(totalOnHand) }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Reserved</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(totalReserved) }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Damaged</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(totalDamaged) }}
                            </dd>
                            <dt class="text-stone-500 dark:text-neutral-400">Minimum</dt>
                            <dd class="text-end text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(product.minimum_stock) }}
                            </dd>
                        </dl>
                    </Card>

                    <Card v-if="galleryImages.length" class="rise-in" :style="{ animationDelay: '200ms' }">
                        <template #title>Galerie</template>
                        <div class="grid grid-cols-3 gap-2">
                            <img
                                v-for="(image, index) in galleryImages"
                                :key="`${image}-${index}`"
                                :src="image"
                                alt="Product image"
                                class="h-20 w-full rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                            />
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes rise {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.rise-in {
    animation: rise 0.45s ease both;
}
</style>
