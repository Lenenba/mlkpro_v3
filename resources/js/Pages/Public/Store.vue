<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    company: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    best_sellers: { type: Array, default: () => [] },
    promotions: { type: Array, default: () => [] },
    new_arrivals: { type: Array, default: () => [] },
    hero_product: { type: Object, default: null },
    cart: { type: Object, default: () => ({}) },
    fulfillment: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const page = usePage();
const search = ref('');
const selectedCategory = ref('all');
const cartState = ref(props.cart || {});
const cartBusy = ref(false);
const cartError = ref('');
const checkoutErrors = ref({});
const checkoutError = ref('');

const checkoutForm = ref({
    name: page.props.auth?.user?.name || '',
    email: page.props.auth?.user?.email || '',
    phone: page.props.auth?.user?.phone_number || '',
    delivery_address: '',
});

const companyName = computed(() => props.company?.name || t('public_store.company_fallback'));
const pageTitle = computed(() => t('public_store.title', { company: companyName.value }));

const isAuthenticated = computed(() => !!page.props.auth?.user);
const isClient = computed(() => !!page.props.auth?.account?.is_client);
const canShop = computed(() => isAuthenticated.value && isClient.value);
const isInternalUser = computed(() => isAuthenticated.value && !isClient.value);

const primaryCtaHref = computed(() => '#cart');
const primaryCtaLabel = computed(() => t('public_store.actions.checkout'));
const bestSellerIds = computed(() => new Set((props.best_sellers || []).map((product) => product.id)));
const promoIds = computed(() => new Set((props.promotions || []).map((product) => product.id)));
const newArrivalIds = computed(() => new Set((props.new_arrivals || []).map((product) => product.id)));

const heroProduct = computed(() =>
    props.hero_product
    || props.best_sellers?.[0]
    || props.promotions?.[0]
    || props.new_arrivals?.[0]
    || props.products?.[0]
    || null,
);

const categoryOptions = computed(() => [
    { id: 'all', name: t('public_store.filters.all') },
    ...(props.categories || []).map((category) => ({ id: String(category.id), name: category.name })),
]);

const categoriesWithCounts = computed(() => (props.categories || []).map((category) => {
    const count = (props.products || []).filter(
        (product) => String(product.category_id || '') === String(category.id),
    ).length;
    return { ...category, count };
}));

const filteredProducts = computed(() => {
    const query = String(search.value || '').toLowerCase().trim();
    return (props.products || []).filter((product) => {
        const matchesCategory =
            selectedCategory.value === 'all'
            || String(product.category_id || '') === String(selectedCategory.value);
        if (!matchesCategory) return false;
        if (!query) return true;
        return (
            String(product.name || '').toLowerCase().includes(query)
            || String(product.description || '').toLowerCase().includes(query)
            || String(product.sku || '').toLowerCase().includes(query)
        );
    });
});

const bestSellersDisplay = computed(() => (props.best_sellers || []).slice(0, 8));
const promotionsDisplay = computed(() => (props.promotions || []).slice(0, 8));
const newArrivalsDisplay = computed(() => (props.new_arrivals || []).slice(0, 8));

const productStats = computed(() => ({
    total: (props.products || []).length,
    categories: (props.categories || []).length,
    newArrivals: (props.new_arrivals || []).length,
}));

const cartItems = computed(() => cartState.value?.items || []);
const cartItemCount = computed(() => Number(cartState.value?.item_count || 0));
const cartSubtotal = computed(() => Number(cartState.value?.subtotal || 0));
const cartTaxTotal = computed(() => Number(cartState.value?.tax_total || 0));
const cartTotal = computed(() => Number(cartState.value?.total || 0));

const fulfillment = computed(() => props.fulfillment || {});
const requiresDeliveryAddress = computed(() =>
    Boolean(fulfillment.value?.delivery_enabled) && !fulfillment.value?.pickup_enabled
);
const deliveryFee = computed(() => (requiresDeliveryAddress.value ? Number(fulfillment.value?.delivery_fee || 0) : 0));
const checkoutTotal = computed(() => cartTotal.value + deliveryFee.value);

const needsIdentity = computed(() => !isAuthenticated.value || !isClient.value);
const canCheckout = computed(() => {
    if (!cartItems.value.length) {
        return false;
    }
    if (isInternalUser.value) {
        return false;
    }
    if (needsIdentity.value) {
        if (!String(checkoutForm.value.name || '').trim()) {
            return false;
        }
        if (!String(checkoutForm.value.email || '').trim()) {
            return false;
        }
    }
    if (requiresDeliveryAddress.value && !String(checkoutForm.value.delivery_address || '').trim()) {
        return false;
    }
    return true;
});

const formatPrice = (value) => {
    const amount = Number(value || 0);
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(amount);
};

const priceMeta = (product) => {
    const promoActive = !!product?.promo_active && Number(product?.promo_price ?? 0) > 0;
    return {
        current: promoActive ? product.promo_price : product?.price,
        original: promoActive ? product?.price : null,
        discount: promoActive ? product?.promo_discount_percent : null,
    };
};

const stockLabel = (product) => {
    const stock = Number(product?.stock ?? 0);
    if (stock <= 0) {
        return { label: t('public_store.stock.out'), tone: 'text-rose-600' };
    }
    if (stock <= 5) {
        return { label: t('public_store.badges.low_stock'), tone: 'text-amber-600' };
    }
    return { label: t('public_store.stock.in'), tone: 'text-emerald-700' };
};

const productBadges = (product) => {
    const badges = [];
    if (bestSellerIds.value.has(product.id)) badges.push({ label: t('public_store.badges.best_seller'), tone: 'bg-amber-100 text-amber-800' });
    if (promoIds.value.has(product.id)) badges.push({ label: t('public_store.badges.promo'), tone: 'bg-rose-100 text-rose-700' });
    if (newArrivalIds.value.has(product.id)) badges.push({ label: t('public_store.badges.new'), tone: 'bg-teal-100 text-teal-800' });
    return badges;
};

const cartQuantity = (productId) =>
    cartItems.value.find((item) => item.product_id === productId)?.quantity || 0;

const applyCartResponse = (response) => {
    if (response?.data?.cart) {
        cartState.value = response.data.cart;
    }
};

const handleCartError = (error) => {
    cartError.value = error?.response?.data?.message || t('public_store.cart_error');
};

const addToCart = async (product) => {
    if (!product || cartBusy.value) {
        return;
    }
    cartError.value = '';
    cartBusy.value = true;
    try {
        const response = await axios.post(route('public.store.cart.add', { slug: props.company?.slug }), {
            product_id: product.id,
            quantity: 1,
        }, { headers: { Accept: 'application/json' } });
        applyCartResponse(response);
    } catch (error) {
        handleCartError(error);
    } finally {
        cartBusy.value = false;
    }
};

const updateCartItem = async (productId, quantity) => {
    if (!productId || cartBusy.value) {
        return;
    }
    cartError.value = '';
    cartBusy.value = true;
    try {
        const response = await axios.patch(
            route('public.store.cart.update', { slug: props.company?.slug, product: productId }),
            { quantity },
            { headers: { Accept: 'application/json' } },
        );
        applyCartResponse(response);
    } catch (error) {
        handleCartError(error);
    } finally {
        cartBusy.value = false;
    }
};

const removeCartItem = async (productId) => {
    if (!productId || cartBusy.value) {
        return;
    }
    cartError.value = '';
    cartBusy.value = true;
    try {
        const response = await axios.delete(
            route('public.store.cart.remove', { slug: props.company?.slug, product: productId }),
            { headers: { Accept: 'application/json' } },
        );
        applyCartResponse(response);
    } catch (error) {
        handleCartError(error);
    } finally {
        cartBusy.value = false;
    }
};

const clearCart = async () => {
    if (cartBusy.value) {
        return;
    }
    cartError.value = '';
    cartBusy.value = true;
    try {
        const response = await axios.delete(
            route('public.store.cart.clear', { slug: props.company?.slug }),
            { headers: { Accept: 'application/json' } },
        );
        applyCartResponse(response);
    } catch (error) {
        handleCartError(error);
    } finally {
        cartBusy.value = false;
    }
};

const checkout = async () => {
    if (!canCheckout.value || cartBusy.value) {
        return;
    }
    checkoutError.value = '';
    checkoutErrors.value = {};
    cartBusy.value = true;
    try {
        const response = await axios.post(
            route('public.store.checkout', { slug: props.company?.slug }),
            checkoutForm.value,
            { headers: { Accept: 'application/json' } },
        );
        if (response?.data?.redirect_url) {
            window.location.href = response.data.redirect_url;
        }
    } catch (error) {
        const rawErrors = error?.response?.data?.errors || {};
        const normalizedErrors = Object.fromEntries(
            Object.entries(rawErrors).map(([key, value]) => [
                key,
                Array.isArray(value) ? value[0] : value,
            ]),
        );
        checkoutErrors.value = normalizedErrors;
        const firstError = Object.values(normalizedErrors)[0];
        checkoutError.value = firstError || error?.response?.data?.message || t('public_store.checkout_error');
    } finally {
        cartBusy.value = false;
    }
};

const selectCategory = (id) => {
    selectedCategory.value = String(id);
};

const hashToHue = (value) => {
    const text = String(value || 'store');
    let hash = 0;
    for (let i = 0; i < text.length; i += 1) {
        hash = (hash << 5) - hash + text.charCodeAt(i);
        hash |= 0;
    }
    return Math.abs(hash) % 360;
};

const themeStyle = computed(() => {
    const seed = props.company?.slug || companyName.value;
    const hue = hashToHue(seed);
    return {
        '--store-accent': `hsl(${hue}, 55%, 42%)`,
        '--store-accent-dark': `hsl(${hue}, 60%, 32%)`,
        '--store-accent-soft': `hsl(${hue}, 70%, 92%)`,
        '--store-accent-soft-alt': `hsl(${hue}, 30%, 94%)`,
        '--store-accent-contrast': '#ffffff',
    };
});
</script>

<template>
    <Head :title="pageTitle">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link
            rel="stylesheet"
            href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap"
        />
    </Head>

    <div class="store-page min-h-screen bg-stone-50 text-slate-900" :style="themeStyle">
        <div class="relative overflow-hidden bg-white">
            <div class="hero-blob hero-blob--left" aria-hidden="true"></div>
            <div class="hero-blob hero-blob--right" aria-hidden="true"></div>

            <header class="relative z-10">
                <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-6">
                    <div class="flex items-center gap-4">
                        <div class="flex size-12 items-center justify-center overflow-hidden rounded-2xl border border-white/70 bg-white/80 shadow-sm">
                            <img v-if="company?.logo_url" :src="company.logo_url" :alt="companyName" class="size-full object-cover" />
                            <span v-else class="text-sm font-semibold">{{ companyName.slice(0, 2).toUpperCase() }}</span>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] store-accent-text">{{ t('public_store.hero.eyebrow') }}</p>
                            <h1 class="store-heading text-xl font-semibold">{{ companyName }}</h1>
                            <p v-if="company?.description" class="text-sm text-slate-500">
                                {{ company.description }}
                            </p>
                            <p v-else class="text-sm text-slate-400">{{ t('public_store.subtitle') }}</p>
                        </div>
                    </div>

                    <div class="ml-auto flex flex-wrap gap-2">
                        <template v-if="canShop">
                            <Link :href="route('portal.orders.index')" class="store-button store-button--primary">
                                {{ t('public_store.actions.shop_now') }}
                            </Link>
                            <a href="#catalog" class="store-button store-button--secondary">
                                {{ t('public_store.actions.view_catalog') }}
                            </a>
                        </template>
                        <template v-else>
                            <Link :href="route('login')" class="store-button store-button--secondary">
                                {{ t('public_store.actions.login') }}
                            </Link>
                            <Link :href="route('register')" class="store-button store-button--primary">
                                {{ t('public_store.actions.register') }}
                            </Link>
                        </template>
                    </div>
                </div>
                <div v-if="!canShop" class="mx-auto max-w-6xl px-4 pb-4 text-xs text-slate-500">
                    {{ t('public_store.actions.portal_hint') }}
                </div>
            </header>

            <section class="relative z-10 mx-auto grid max-w-6xl gap-8 px-4 pb-12 pt-4 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="fade-in-up space-y-6">
                    <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-widest text-slate-500">
                        {{ t('public_store.sections.spotlight') }}
                    </div>
                    <h2 class="store-heading text-3xl font-semibold leading-tight text-slate-900 sm:text-4xl">
                        {{ t('public_store.hero.headline', { company: companyName }) }}
                    </h2>
                    <p class="text-base text-slate-600 sm:text-lg">
                        {{ t('public_store.hero.subheadline') }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <template v-if="canShop">
                            <a href="#catalog" class="store-button store-button--secondary">
                                {{ t('public_store.actions.view_catalog') }}
                            </a>
                        </template>
                        <template v-else>
                            <Link :href="route('register')" class="store-button store-button--secondary">
                                {{ t('public_store.actions.register') }}
                            </Link>
                        </template>
                        <a :href="primaryCtaHref" class="store-button store-button--primary">
                            {{ primaryCtaLabel }}
                        </a>
                    </div>
                    <div class="flex flex-wrap gap-6 text-sm text-slate-500">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-400">{{ t('public_store.hero.stat_products', { count: productStats.total }) }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-400">{{ t('public_store.hero.stat_categories', { count: productStats.categories }) }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-400">{{ t('public_store.hero.stat_new_arrivals', { count: productStats.newArrivals }) }}</p>
                        </div>
                    </div>
                </div>

                <div v-if="heroProduct" class="fade-in-up hero-card">
                    <div class="rounded-3xl border border-slate-100 bg-white p-6 shadow-xl shadow-slate-900/10">
                        <div class="flex items-start justify-between">
                            <div class="space-y-2">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">{{ t('public_store.sections.spotlight') }}</p>
                                <h3 class="store-heading text-xl font-semibold text-slate-900">
                                    {{ heroProduct.name }}
                                </h3>
                            </div>
                            <div class="flex gap-2">
                                <span v-for="badge in productBadges(heroProduct)" :key="badge.label" :class="badge.tone" class="rounded-full px-2.5 py-1 text-xs font-semibold">
                                    {{ badge.label }}
                                </span>
                            </div>
                        </div>
                        <div class="mt-4 flex items-center gap-4">
                            <div class="relative size-28 overflow-hidden rounded-2xl border border-slate-100 bg-stone-100">
                                <img v-if="heroProduct.image_url" :src="heroProduct.image_url" :alt="heroProduct.name" class="size-full object-cover" />
                            </div>
                            <div class="flex-1 space-y-2">
                                <p class="text-sm text-slate-500 line-clamp-2">
                                    {{ heroProduct.description || t('public_store.empty_description') }}
                                </p>
                                <div class="flex items-center justify-between">
                                    <div class="flex flex-col">
                                        <span class="text-lg font-semibold text-slate-900">{{ formatPrice(priceMeta(heroProduct).current) }}</span>
                                        <span v-if="priceMeta(heroProduct).original" class="text-xs text-slate-400 line-through">
                                            {{ formatPrice(priceMeta(heroProduct).original) }}
                                        </span>
                                    </div>
                                    <span :class="stockLabel(heroProduct).tone" class="text-xs font-semibold">
                                        {{ stockLabel(heroProduct).label }}
                                    </span>
                                </div>
                                <a :href="primaryCtaHref" class="inline-flex items-center gap-2 text-sm font-semibold store-accent-text">
                                    {{ t('public_store.actions.view_product') }}
                                    <span aria-hidden="true">-></span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <main class="mx-auto max-w-6xl space-y-12 px-4 pb-16 pt-10">
            <section class="fade-in-up space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h3 class="store-heading text-xl font-semibold">{{ t('public_store.sections.categories') }}</h3>
                    <p class="text-sm text-slate-500">{{ t('public_store.hints.login_to_order') }}</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="button"
                        class="store-button store-button--secondary"
                        :class="{ 'store-button--primary': selectedCategory === 'all' }"
                        @click="selectCategory('all')">
                        {{ t('public_store.filters.all') }}
                    </button>
                    <button v-for="category in categoriesWithCounts" :key="category.id" type="button"
                        class="store-button store-button--secondary"
                        :class="{ 'store-button--primary': String(selectedCategory) === String(category.id) }"
                        @click="selectCategory(category.id)">
                        {{ category.name }}
                        <span class="ml-2 text-xs text-slate-400">({{ category.count }})</span>
                    </button>
                </div>
            </section>

            <section class="fade-in-up space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="store-heading text-xl font-semibold">{{ t('public_store.sections.best_sellers') }}</h3>
                    <a href="#catalog" class="text-sm font-semibold store-accent-text">{{ t('public_store.actions.explore') }}</a>
                </div>
                <div v-if="bestSellersDisplay.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <article v-for="(product, index) in bestSellersDisplay" :key="product.id" class="product-card" :style="{ animationDelay: `${index * 80}ms` }">
                        <div class="flex items-start justify-between">
                            <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                                {{ t('public_store.badges.best_seller') }}
                            </span>
                            <span class="text-xs text-slate-400">#{{ index + 1 }}</span>
                        </div>
                        <div class="mt-4 flex items-center gap-3">
                            <div class="size-16 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                                <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="size-full object-cover" />
                            </div>
                            <div>
                                <h4 class="text-sm font-semibold text-slate-900">{{ product.name }}</h4>
                                <div class="text-xs text-slate-500">
                                    <span class="font-semibold text-slate-900">{{ formatPrice(priceMeta(product).current) }}</span>
                                    <span v-if="priceMeta(product).original" class="ml-2 text-[11px] text-slate-400 line-through">
                                        {{ formatPrice(priceMeta(product).original) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
                <p v-else class="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-6 text-sm text-slate-500">
                    {{ t('public_store.empty_collection') }}
                </p>
            </section>

            <section class="fade-in-up grid gap-6 lg:grid-cols-[1fr_1.2fr]">
                <div class="rounded-3xl bg-slate-900 p-6 text-white shadow-xl shadow-slate-900/20">
                    <h3 class="store-heading text-xl font-semibold">{{ t('public_store.sections.promotions') }}</h3>
                    <p class="mt-2 text-sm text-slate-200">
                        {{ t('public_store.promotions_hint') }}
                    </p>
                    <div class="mt-6 space-y-3">
                        <div v-for="product in promotionsDisplay.slice(0, 3)" :key="product.id"
                            class="flex items-center justify-between rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                            <div>
                                <p class="text-sm font-semibold">{{ product.name }}</p>
                                <p class="text-xs text-slate-300">{{ formatPrice(product.price) }}</p>
                            </div>
                            <span class="rounded-full bg-white/10 px-2.5 py-1 text-xs font-semibold">{{ t('public_store.badges.promo') }}</span>
                        </div>
                        <p v-if="!promotionsDisplay.length" class="text-sm text-slate-300">
                            {{ t('public_store.empty_collection') }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <article v-for="(product, index) in promotionsDisplay" :key="product.id"
                        class="product-card" :style="{ animationDelay: `${index * 80}ms` }">
                        <div class="flex items-start justify-between">
                            <span class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700">
                                {{ t('public_store.badges.promo') }}
                            </span>
                            <span :class="stockLabel(product).tone" class="text-xs font-semibold">{{ stockLabel(product).label }}</span>
                        </div>
                        <div class="mt-4 flex items-center gap-3">
                            <div class="size-16 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                                <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="size-full object-cover" />
                            </div>
                            <div>
                                <h4 class="text-sm font-semibold text-slate-900">{{ product.name }}</h4>
                                <div class="text-xs text-slate-500">
                                    <span class="font-semibold text-slate-900">{{ formatPrice(priceMeta(product).current) }}</span>
                                    <span v-if="priceMeta(product).original" class="ml-2 text-[11px] text-slate-400 line-through">
                                        {{ formatPrice(priceMeta(product).original) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="fade-in-up space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="store-heading text-xl font-semibold">{{ t('public_store.sections.new_arrivals') }}</h3>
                    <a href="#catalog" class="text-sm font-semibold store-accent-text">{{ t('public_store.actions.explore') }}</a>
                </div>
                <div v-if="newArrivalsDisplay.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <article v-for="(product, index) in newArrivalsDisplay" :key="product.id" class="product-card" :style="{ animationDelay: `${index * 80}ms` }">
                        <div class="flex items-start justify-between">
                            <span class="rounded-full bg-teal-100 px-2.5 py-1 text-xs font-semibold text-teal-700">
                                {{ t('public_store.badges.new') }}
                            </span>
                            <span :class="stockLabel(product).tone" class="text-xs font-semibold">{{ stockLabel(product).label }}</span>
                        </div>
                        <div class="mt-4 flex items-center gap-3">
                            <div class="size-16 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                                <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="size-full object-cover" />
                            </div>
                            <div>
                                <h4 class="text-sm font-semibold text-slate-900">{{ product.name }}</h4>
                                <div class="text-xs text-slate-500">
                                    <span class="font-semibold text-slate-900">{{ formatPrice(priceMeta(product).current) }}</span>
                                    <span v-if="priceMeta(product).original" class="ml-2 text-[11px] text-slate-400 line-through">
                                        {{ formatPrice(priceMeta(product).original) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
                <p v-else class="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-6 text-sm text-slate-500">
                    {{ t('public_store.empty_collection') }}
                </p>
            </section>

            <section id="catalog" class="fade-in-up space-y-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h3 class="store-heading text-xl font-semibold">{{ t('public_store.sections.catalog') }}</h3>
                    <div class="text-sm text-slate-500">
                        {{ filteredProducts.length }} / {{ products.length }}
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-[1fr_220px]">
                    <label class="sr-only" for="store-search">{{ t('public_store.filters.search') }}</label>
                    <input id="store-search" v-model="search"
                        :placeholder="t('public_store.search_placeholder')"
                        class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:border-slate-400 focus:ring-slate-400" />
                    <label class="sr-only" for="store-category">{{ t('public_store.filters.category') }}</label>
                    <select id="store-category" v-model="selectedCategory"
                        class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:border-slate-400 focus:ring-slate-400">
                        <option v-for="option in categoryOptions" :key="option.id" :value="option.id">
                            {{ option.name }}
                        </option>
                    </select>
                </div>

                <section v-if="filteredProducts.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="(product, index) in filteredProducts" :key="product.id" class="product-card" :style="{ animationDelay: `${index * 40}ms` }">
                        <div class="flex items-start gap-3">
                            <div class="size-20 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                                <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="size-full object-cover" />
                            </div>
                            <div class="flex-1">
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="badge in productBadges(product)" :key="badge.label" :class="badge.tone" class="rounded-full px-2.5 py-1 text-xs font-semibold">
                                        {{ badge.label }}
                                    </span>
                                </div>
                                <h4 class="mt-2 text-sm font-semibold text-slate-900">{{ product.name }}</h4>
                                <p v-if="product.description" class="text-xs text-slate-500 line-clamp-2">
                                    {{ product.description }}
                                </p>
                                <p v-else class="text-xs text-slate-400">{{ t('public_store.empty_description') }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between text-sm">
                            <div class="flex flex-col">
                                <span class="font-semibold text-slate-900">{{ formatPrice(priceMeta(product).current) }}</span>
                                <span v-if="priceMeta(product).original" class="text-[11px] text-slate-400 line-through">
                                    {{ formatPrice(priceMeta(product).original) }}
                                </span>
                            </div>
                            <span :class="stockLabel(product).tone" class="text-xs font-semibold">
                                {{ stockLabel(product).label }}
                            </span>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-2">
                            <button
                                v-if="cartQuantity(product.id) === 0"
                                type="button"
                                class="store-button store-button--secondary w-full"
                                :disabled="cartBusy || Number(product.stock || 0) <= 0"
                                @click="addToCart(product)"
                            >
                                {{ t('public_store.actions.add_to_cart') }}
                            </button>
                            <div v-else class="flex w-full items-center justify-between gap-2">
                                <button
                                    type="button"
                                    class="store-button store-button--secondary w-10 px-0"
                                    :disabled="cartBusy"
                                    @click="updateCartItem(product.id, cartQuantity(product.id) - 1)"
                                >
                                    -
                                </button>
                                <span class="text-sm font-semibold text-slate-700">
                                    {{ cartQuantity(product.id) }}
                                </span>
                                <button
                                    type="button"
                                    class="store-button store-button--primary w-10 px-0"
                                    :disabled="cartBusy || cartQuantity(product.id) >= Number(product.stock || 0)"
                                    @click="updateCartItem(product.id, cartQuantity(product.id) + 1)"
                                >
                                    +
                                </button>
                            </div>
                        </div>
                    </article>
                </section>

                <section v-else class="rounded-2xl border border-dashed border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
                    {{ t('public_store.empty') }}
                </section>
            </section>

            <section id="cart" class="fade-in-up space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="store-heading text-xl font-semibold">{{ t('public_store.cart.title') }}</h3>
                    <div class="flex items-center gap-3 text-sm text-slate-500">
                        <span v-if="cartItemCount">{{ t('public_store.cart.item_count', { count: cartItemCount }) }}</span>
                        <button
                            v-if="cartItems.length"
                            type="button"
                            class="text-xs font-semibold store-accent-text"
                            :disabled="cartBusy"
                            @click="clearCart"
                        >
                            {{ t('public_store.cart.clear') }}
                        </button>
                    </div>
                </div>

                <div v-if="isInternalUser" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    {{ t('public_store.cart.internal_notice') }}
                </div>

                <div v-if="cartError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {{ cartError }}
                </div>

                <div v-if="cartItems.length" class="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                    <div class="space-y-3">
                        <article v-for="item in cartItems" :key="item.product_id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3">
                                    <div class="size-16 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                                        <img v-if="item.image_url" :src="item.image_url" :alt="item.name" class="size-full object-cover" />
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-semibold text-slate-900">{{ item.name }}</h4>
                                        <p class="text-xs text-slate-400">{{ item.sku || t('public_store.cart.sku_fallback') }}</p>
                                        <div class="mt-1 text-xs text-slate-500">
                                            <span class="font-semibold text-slate-900">{{ formatPrice(item.price) }}</span>
                                            <span v-if="item.promo_active" class="ml-2 text-[11px] text-slate-400 line-through">
                                                {{ formatPrice(item.base_price) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-slate-400 hover:text-slate-600"
                                    :disabled="cartBusy"
                                    @click="removeCartItem(item.product_id)"
                                >
                                    {{ t('public_store.cart.remove') }}
                                </button>
                            </div>
                            <div class="mt-3 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="store-button store-button--secondary w-9 px-0"
                                        :disabled="cartBusy"
                                        @click="updateCartItem(item.product_id, item.quantity - 1)"
                                    >
                                        -
                                    </button>
                                    <span class="text-sm font-semibold text-slate-700">{{ item.quantity }}</span>
                                    <button
                                        type="button"
                                        class="store-button store-button--primary w-9 px-0"
                                        :disabled="cartBusy || item.quantity >= Number(item.stock || 0)"
                                        @click="updateCartItem(item.product_id, item.quantity + 1)"
                                    >
                                        +
                                    </button>
                                </div>
                                <div class="text-sm font-semibold text-slate-900">
                                    {{ formatPrice(item.line_total) }}
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="space-y-4">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <h4 class="text-sm font-semibold text-slate-900">{{ t('public_store.cart.summary') }}</h4>
                            <div class="mt-3 space-y-2 text-sm text-slate-600">
                                <div class="flex items-center justify-between">
                                    <span>{{ t('public_store.cart.subtotal') }}</span>
                                    <span class="font-semibold text-slate-900">{{ formatPrice(cartSubtotal) }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>{{ t('public_store.cart.taxes') }}</span>
                                    <span class="font-semibold text-slate-900">{{ formatPrice(cartTaxTotal) }}</span>
                                </div>
                                <div v-if="deliveryFee" class="flex items-center justify-between">
                                    <span>{{ t('public_store.cart.delivery_fee') }}</span>
                                    <span class="font-semibold text-slate-900">{{ formatPrice(deliveryFee) }}</span>
                                </div>
                                <div class="flex items-center justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-900">
                                    <span>{{ t('public_store.cart.total') }}</span>
                                    <span>{{ formatPrice(checkoutTotal) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <h4 class="text-sm font-semibold text-slate-900">{{ t('public_store.cart.checkout') }}</h4>
                            <p class="mt-1 text-xs text-slate-500">{{ t('public_store.cart.checkout_hint') }}</p>

                            <div v-if="needsIdentity" class="mt-4 space-y-3">
                                <div>
                                    <label class="text-xs font-semibold text-slate-500">{{ t('public_store.cart.name') }}</label>
                                    <input v-model="checkoutForm.name"
                                        class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2 text-sm"
                                        type="text" />
                                    <p v-if="checkoutErrors.name" class="mt-1 text-xs text-rose-600">{{ checkoutErrors.name }}</p>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-500">{{ t('public_store.cart.email') }}</label>
                                    <input v-model="checkoutForm.email"
                                        class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2 text-sm"
                                        type="email" />
                                    <p v-if="checkoutErrors.email" class="mt-1 text-xs text-rose-600">{{ checkoutErrors.email }}</p>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-500">{{ t('public_store.cart.phone') }}</label>
                                    <input v-model="checkoutForm.phone"
                                        class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2 text-sm"
                                        type="tel" />
                                    <p v-if="checkoutErrors.phone" class="mt-1 text-xs text-rose-600">{{ checkoutErrors.phone }}</p>
                                </div>
                            </div>

                            <div v-if="requiresDeliveryAddress" class="mt-4">
                                <label class="text-xs font-semibold text-slate-500">{{ t('public_store.cart.delivery_address') }}</label>
                                <textarea v-model="checkoutForm.delivery_address"
                                    class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2 text-sm"
                                    rows="2"></textarea>
                                <p v-if="checkoutErrors.delivery_address" class="mt-1 text-xs text-rose-600">{{ checkoutErrors.delivery_address }}</p>
                            </div>

                            <p v-if="checkoutError" class="mt-3 text-xs text-rose-600">{{ checkoutError }}</p>

                            <button
                                type="button"
                                class="store-button store-button--primary mt-4 w-full"
                                :disabled="cartBusy || !canCheckout"
                                @click="checkout"
                            >
                                {{ t('public_store.cart.submit') }}
                            </button>
                        </div>
                    </div>
                </div>

                <p v-else class="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-6 text-sm text-slate-500">
                    {{ t('public_store.cart.empty') }}
                </p>
            </section>
        </main>
    </div>
</template>

<style scoped>
.store-page {
    font-family: 'DM Sans', sans-serif;
}

.store-heading {
    font-family: 'Space Grotesk', sans-serif;
}

.store-accent-text {
    color: var(--store-accent);
}

.store-button {
    border-radius: 999px;
    padding: 0.55rem 1.25rem;
    font-weight: 600;
    font-size: 0.875rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease;
}

.store-button--primary {
    background-color: var(--store-accent);
    color: var(--store-accent-contrast);
    box-shadow: 0 18px 35px -25px rgba(15, 23, 42, 0.45);
    border: 1px solid transparent;
}

.store-button--secondary {
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    color: #334155;
}

.store-button--primary:hover,
.store-button--secondary:hover {
    transform: translateY(-2px);
}

.hero-blob {
    position: absolute;
    width: 320px;
    height: 320px;
    border-radius: 999px;
    opacity: 0.4;
    animation: float 12s ease-in-out infinite;
}

.hero-blob--left {
    top: -120px;
    left: -80px;
    background-color: var(--store-accent-soft);
}

.hero-blob--right {
    bottom: -120px;
    right: -80px;
    background-color: var(--store-accent-soft-alt);
    animation-delay: 2s;
}

.hero-card {
    animation: fadeUp 0.8s ease both 0.15s;
}

.fade-in-up {
    animation: fadeUp 0.7s ease both;
}

.product-card {
    border-radius: 24px;
    border: 1px solid rgba(226, 232, 240, 1);
    background: white;
    padding: 16px;
    box-shadow: 0 20px 40px -30px rgba(15, 23, 42, 0.35);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    animation: fadeUp 0.7s ease both;
}

.product-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 30px 60px -30px rgba(15, 23, 42, 0.45);
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(18px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes float {
    0%,
    100% {
        transform: translateY(0px);
    }
    50% {
        transform: translateY(18px);
    }
}
</style>
