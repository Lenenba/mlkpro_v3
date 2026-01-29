<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import Badge from '@/Components/Store/Badge.vue';
import CategoryChips from '@/Components/Store/CategoryChips.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FlashToaster from '@/Components/UI/FlashToaster.vue';
import Price from '@/Components/Store/Price.vue';
import ProductCard from '@/Components/Store/ProductCard.vue';
import ProductSection from '@/Components/Store/ProductSection.vue';
import SectionHeader from '@/Components/Store/SectionHeader.vue';

const props = defineProps({
    company: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    best_sellers: { type: Array, default: () => [] },
    promotions: { type: Array, default: () => [] },
    new_arrivals: { type: Array, default: () => [] },
    hero_product: { type: Object, default: () => null },
    categories: { type: Array, default: () => [] },
    cart: {
        type: Object,
        default: () => ({
            items: [],
            subtotal: 0,
            tax_total: 0,
            total: 0,
            item_count: 0,
        }),
    },
    fulfillment: { type: Object, default: () => ({}) },
});

const { t, locale } = useI18n();
const page = usePage();

const authUser = computed(() => page.props.auth?.user || null);
const authAccount = computed(() => page.props.auth?.account || {});
const isAuthenticated = computed(() => Boolean(authUser.value));
const isInternalUser = computed(() => isAuthenticated.value && !authAccount.value?.is_client);

const company = computed(() => props.company || {});
const companyName = computed(() => company.value?.name || t('public_store.company_fallback'));
const pageTitle = computed(() => t('public_store.title', { company: companyName.value }));

const searchQuery = ref('');
const selectedCategory = ref('');
const sortOption = ref('featured');
const priceMin = ref('');
const priceMax = ref('');
const availabilityFilter = ref('all');
const promoFilter = ref('all');
const cartVisible = ref(false);
const cartBusy = ref(false);
const cartError = ref('');
const checkoutError = ref('');
const checkoutProcessing = ref(false);
const selectedProduct = ref(null);
const showProductDetails = ref(false);
const activeImage = ref('');
const cartPulse = ref(false);
const productReviews = ref([]);
const reviewsLoading = ref(false);
const reviewsError = ref('');
const reviewsCache = ref({});
const pageLoading = ref(true);
const pageSize = ref(24);
const currentPage = ref(1);
const filtersRestored = ref(false);

const cartData = ref({
    items: props.cart?.items || [],
    subtotal: props.cart?.subtotal || 0,
    tax_total: props.cart?.tax_total || 0,
    total: props.cart?.total || 0,
    item_count: props.cart?.item_count || 0,
});

const checkoutForm = ref({
    name: authUser.value?.name || '',
    email: authUser.value?.email || '',
    phone: authUser.value?.phone_number || '',
    delivery_address: '',
    delivery_notes: '',
    pickup_notes: '',
    scheduled_for: '',
    customer_notes: '',
    substitution_allowed: true,
    substitution_notes: '',
    fulfillment_method: '',
});
const checkoutErrors = ref({});

const heroProduct = computed(() => props.hero_product || null);
const bestSellers = computed(() => props.best_sellers || []);
const promotions = computed(() => props.promotions || []);
const newArrivals = computed(() => props.new_arrivals || []);
const categories = computed(() => props.categories || []);

const productImages = computed(() => {
    if (!selectedProduct.value) {
        return [];
    }
    const images = Array.isArray(selectedProduct.value.images) ? selectedProduct.value.images : [];
    const merged = [selectedProduct.value.image_url, ...images].filter(Boolean);
    return [...new Set(merged)];
});

const activeImageSrc = computed(() => {
    if (!selectedProduct.value) {
        return '';
    }
    const images = productImages.value;
    if (activeImage.value && images.includes(activeImage.value)) {
        return activeImage.value;
    }
    return images[0] || '';
});

const productCount = computed(() => props.products?.length || 0);
const categoryCount = computed(() => categories.value.length);
const newArrivalCount = computed(() => newArrivals.value.length);

const heroStats = computed(() => ([
    t('public_store.hero.stat_products', { count: productCount.value }),
    t('public_store.hero.stat_categories', { count: categoryCount.value }),
    t('public_store.hero.stat_new_arrivals', { count: newArrivalCount.value }),
]));

const bestSellerIds = computed(() => new Set(bestSellers.value.map((item) => item?.id).filter(Boolean)));
const newArrivalIds = computed(() => new Set(newArrivals.value.map((item) => item?.id).filter(Boolean)));

const cartItems = computed(() => cartData.value?.items || []);
const cartSubtotal = computed(() => Number(cartData.value?.subtotal || 0));
const cartTaxes = computed(() => Number(cartData.value?.tax_total || 0));
const cartTotal = computed(() => Number(cartData.value?.total || 0));
const cartItemCount = computed(() => Number(cartData.value?.item_count || 0));

const triggerCartPulse = () => {
    cartPulse.value = true;
    setTimeout(() => {
        cartPulse.value = false;
    }, 300);
};

const emitToast = (message, type = 'success') => {
    if (!message || typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent('mlk-toast', { detail: { type, message } }));
};

watch(cartItemCount, (next, prev) => {
    if (next !== prev) {
        triggerCartPulse();
    }
});

const fulfillmentSettings = computed(() => props.fulfillment || {});
const fulfillmentMethod = ref(
    fulfillmentSettings.value?.delivery_enabled
        ? 'delivery'
        : (fulfillmentSettings.value?.pickup_enabled ? 'pickup' : null),
);
checkoutForm.value.fulfillment_method = fulfillmentMethod.value;

const deliveryEnabled = computed(() => Boolean(fulfillmentSettings.value?.delivery_enabled));
const pickupEnabled = computed(() => Boolean(fulfillmentSettings.value?.pickup_enabled));
const fulfillmentUnavailable = computed(() => !deliveryEnabled.value && !pickupEnabled.value);

const setFulfillmentMethod = (method) => {
    if (method === 'delivery' && !deliveryEnabled.value) {
        return;
    }
    if (method === 'pickup' && !pickupEnabled.value) {
        return;
    }
    fulfillmentMethod.value = method;
    checkoutForm.value.fulfillment_method = method;
};

const deliveryFee = computed(() => (
    fulfillmentMethod.value === 'delivery'
        ? Number(fulfillmentSettings.value?.delivery_fee || 0)
        : 0
));
const deliveryFeeAmount = computed(() => Number(fulfillmentSettings.value?.delivery_fee || 0));
const checkoutTotal = computed(() => cartTotal.value + deliveryFee.value);
const showFulfillmentChoice = computed(() => deliveryEnabled.value && pickupEnabled.value);
const deliveryZone = computed(() => fulfillmentSettings.value?.delivery_zone || '');
const pickupAddress = computed(() => fulfillmentSettings.value?.pickup_address || '');
const prepTime = computed(() => Number(fulfillmentSettings.value?.prep_time_minutes || 0));

watch(fulfillmentMethod, (next) => {
    if (next === 'pickup') {
        checkoutForm.value.delivery_address = '';
        checkoutForm.value.delivery_notes = '';
    }
    if (!next) {
        checkoutForm.value.delivery_address = '';
        checkoutForm.value.delivery_notes = '';
        checkoutForm.value.pickup_notes = '';
    }
});

watch(
    fulfillmentSettings,
    (next) => {
        const deliveryAllowed = Boolean(next?.delivery_enabled);
        const pickupAllowed = Boolean(next?.pickup_enabled);
        if (!deliveryAllowed && !pickupAllowed) {
            fulfillmentMethod.value = null;
            checkoutForm.value.fulfillment_method = null;
            return;
        }
        if (deliveryAllowed && !pickupAllowed) {
            setFulfillmentMethod('delivery');
        }
        if (pickupAllowed && !deliveryAllowed) {
            setFulfillmentMethod('pickup');
        }
        if (deliveryAllowed && pickupAllowed && !fulfillmentMethod.value) {
            setFulfillmentMethod('delivery');
        }
    },
    { immediate: true },
);

const portalLink = computed(() => {
    if (!isAuthenticated.value) {
        return null;
    }
    if (authAccount.value?.is_client) {
        return route('portal.orders.index');
    }
    return route('dashboard');
});

const formatCurrency = (value) => {
    const numeric = Number(value || 0);
    return `$${numeric.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const priceMeta = (product) => {
    if (!product) {
        return { current: 0, original: null, promoActive: false };
    }
    const promoActive = Boolean(product.promo_active) && Number(product.promo_price || 0) > 0;
    const current = promoActive ? Number(product.promo_price || 0) : Number(product.price || 0);
    const original = promoActive ? Number(product.price || 0) : null;
    return { current, original, promoActive };
};

const stockLabel = (product) => {
    const stock = Number(product?.stock || 0);
    if (stock <= 0) {
        return t('public_store.stock.out');
    }
    if (stock <= 5) {
        return t('public_store.stock.low');
    }
    return t('public_store.stock.in');
};

const stockTone = (product) => {
    const stock = Number(product?.stock || 0);
    if (stock <= 0) {
        return 'danger';
    }
    if (stock <= 5) {
        return 'warning';
    }
    return 'neutral';
};

const promoBadge = (product) => {
    const discount = Number(product?.promo_discount_percent || 0);
    if (discount > 0) {
        return t('public_store.badges.promo_with', { percent: discount });
    }
    return t('public_store.badges.promo');
};

const categoryLabel = (product) => {
    const categoryId = product?.category_id;
    if (!categoryId) {
        return t('public_store.product.category_fallback');
    }
    const match = categories.value.find((category) => String(category.id) === String(categoryId));
    return match?.name || t('public_store.product.category_fallback');
};

const badgeList = (product) => {
    const badges = [];

    if (product?.promo_active) {
        badges.push({ label: promoBadge(product), tone: 'promo' });
    } else if (bestSellerIds.value.has(product?.id)) {
        badges.push({ label: t('public_store.badges.best_seller'), tone: 'primary' });
    }

    if (newArrivalIds.value.has(product?.id)) {
        badges.push({ label: t('public_store.badges.new'), tone: 'neutral' });
    }

    const stock = Number(product?.stock || 0);
    if (stock > 0 && stock <= 5) {
        badges.push({ label: t('public_store.badges.low_stock'), tone: 'warning' });
    }

    return badges;
};

const heroBadges = computed(() => {
    if (!heroProduct.value) {
        return [];
    }
    return [{ label: t('public_store.sections.spotlight'), tone: 'dark' }, ...badgeList(heroProduct.value)];
});

const filteredProducts = computed(() => {
    const keyword = searchQuery.value.trim().toLowerCase();
    const minPrice = Number(priceMin.value);
    const maxPrice = Number(priceMax.value);
    const hasMinPrice = Number.isFinite(minPrice) && priceMin.value !== '';
    const hasMaxPrice = Number.isFinite(maxPrice) && priceMax.value !== '';

    return (props.products || []).filter((product) => {
        const matchesSearch = !keyword
            || [product.name, product.description, product.sku]
                .filter(Boolean)
                .some((field) => String(field).toLowerCase().includes(keyword));
        const matchesCategory = !selectedCategory.value
            || String(product.category_id || '') === String(selectedCategory.value);
        const currentPrice = priceMeta(product).current;
        const matchesMin = !hasMinPrice || currentPrice >= minPrice;
        const matchesMax = !hasMaxPrice || currentPrice <= maxPrice;
        const stockValue = Number(product?.stock || 0);
        const matchesAvailability = availabilityFilter.value === 'all'
            || (availabilityFilter.value === 'in_stock' && stockValue > 0)
            || (availabilityFilter.value === 'out_of_stock' && stockValue <= 0)
            || (availabilityFilter.value === 'low_stock' && stockValue > 0 && stockValue <= 5);
        const matchesPromo = promoFilter.value === 'all'
            || (promoFilter.value === 'promo' && Boolean(product?.promo_active));

        return matchesSearch
            && matchesCategory
            && matchesMin
            && matchesMax
            && matchesAvailability
            && matchesPromo;
    });
});

const sortOptions = computed(() => ([
    { value: 'featured', label: t('public_store.sort.featured') },
    { value: 'newest', label: t('public_store.sort.newest') },
    { value: 'price_asc', label: t('public_store.sort.price_asc') },
    { value: 'price_desc', label: t('public_store.sort.price_desc') },
    { value: 'name_asc', label: t('public_store.sort.name_asc') },
    { value: 'stock_desc', label: t('public_store.sort.stock_desc') },
]));

const categoryOptions = computed(() => ([
    { value: '', label: t('public_store.filters.all') },
    ...categories.value.map((category) => ({
        value: String(category.id),
        label: category.name,
    })),
]));

const availabilityOptions = computed(() => ([
    { value: 'all', label: t('public_store.filters.availability_all') },
    { value: 'in_stock', label: t('public_store.filters.availability_in') },
    { value: 'low_stock', label: t('public_store.filters.availability_low') },
    { value: 'out_of_stock', label: t('public_store.filters.availability_out') },
]));

const promoOptions = computed(() => ([
    { value: 'all', label: t('public_store.filters.promo_all') },
    { value: 'promo', label: t('public_store.filters.promo_only') },
]));

const sortedProducts = computed(() => {
    const items = [...filteredProducts.value];
    const sortKey = sortOption.value;

    if (sortKey === 'price_asc') {
        items.sort((a, b) => priceMeta(a).current - priceMeta(b).current);
    } else if (sortKey === 'price_desc') {
        items.sort((a, b) => priceMeta(b).current - priceMeta(a).current);
    } else if (sortKey === 'name_asc') {
        items.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
    } else if (sortKey === 'newest') {
        items.sort((a, b) => new Date(b.created_at || 0).getTime() - new Date(a.created_at || 0).getTime());
    } else if (sortKey === 'stock_desc') {
        items.sort((a, b) => Number(b.stock || 0) - Number(a.stock || 0));
    }

    return items;
});

const totalPages = computed(() => Math.max(1, Math.ceil(sortedProducts.value.length / pageSize.value)));
const pagedProducts = computed(() => {
    const start = (currentPage.value - 1) * pageSize.value;
    return sortedProducts.value.slice(start, start + pageSize.value);
});

watch(
    [searchQuery, selectedCategory, sortOption, priceMin, priceMax, availabilityFilter, promoFilter],
    () => {
        currentPage.value = 1;
    },
);

watch(
    [searchQuery, selectedCategory, sortOption, priceMin, priceMax, availabilityFilter, promoFilter],
    () => {
        if (!filtersRestored.value) {
            return;
        }
        persistFilters();
    },
);

watch(
    () => sortedProducts.value.length,
    () => {
        if (currentPage.value > totalPages.value) {
            currentPage.value = totalPages.value;
        }
    },
);

const cartQuantity = (productId) => {
    const item = cartItems.value.find((entry) => entry.product_id === productId);
    return item ? Number(item.quantity || 0) : 0;
};

const setCategory = (categoryId) => {
    selectedCategory.value = categoryId ? String(categoryId) : '';
};

const setCategoryAndScroll = (categoryId) => {
    setCategory(categoryId);
    scrollToCatalog();
};

const scrollToSection = (id) => {
    if (typeof document === 'undefined') {
        return;
    }
    const element = document.getElementById(id);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

const scrollToCatalog = () => scrollToSection('catalog');

const openProductDetails = (product) => {
    if (!product) {
        return;
    }
    selectedProduct.value = product;
    showProductDetails.value = true;
    const images = [product.image_url, ...(product.images || [])].filter(Boolean);
    activeImage.value = images[0] || '';
    productReviews.value = [];
    reviewsError.value = '';
    loadProductReviews(product);
};

const closeProductDetails = () => {
    showProductDetails.value = false;
    selectedProduct.value = null;
    activeImage.value = '';
    productReviews.value = [];
    reviewsError.value = '';
    reviewsLoading.value = false;
};

const setActiveImage = (image) => {
    activeImage.value = image;
};

const relatedProducts = computed(() => {
    if (!selectedProduct.value) {
        return [];
    }
    const base = (props.products || []).filter((product) => product?.id !== selectedProduct.value.id);
    const categoryMatches = base.filter((product) => product?.category_id && product.category_id === selectedProduct.value.category_id);
    const items = categoryMatches.length ? categoryMatches : base;
    return items.slice(0, 4);
});

const headerColor = computed(() => company.value?.store_settings?.header_color || '');
const headerIsCustom = computed(() => Boolean(headerColor.value));
const headerStyle = computed(() => (headerIsCustom.value ? { backgroundColor: headerColor.value } : {}));

const heroBackgroundIndex = ref(0);
const heroBackgroundInterval = ref(null);
const heroBackgrounds = computed(() => {
    const images = company.value?.store_settings?.hero_images;
    if (!Array.isArray(images)) {
        return [];
    }
    return images.map((image) => String(image || '').trim()).filter(Boolean);
});

const heroBackgroundStyle = computed(() => {
    if (!heroBackgrounds.value.length) {
        return {};
    }
    const image = heroBackgrounds.value[heroBackgroundIndex.value] || heroBackgrounds.value[0];
    return { backgroundImage: `url(${image})` };
});

const clearHeroCarousel = () => {
    if (heroBackgroundInterval.value && typeof window !== 'undefined') {
        window.clearInterval(heroBackgroundInterval.value);
        heroBackgroundInterval.value = null;
    }
};

const startHeroCarousel = () => {
    clearHeroCarousel();
    if (typeof window === 'undefined') {
        return;
    }
    if (heroBackgrounds.value.length <= 1) {
        return;
    }
    heroBackgroundInterval.value = window.setInterval(() => {
        heroBackgroundIndex.value = (heroBackgroundIndex.value + 1) % heroBackgrounds.value.length;
    }, 6000);
};

watch(
    heroBackgrounds,
    () => {
        heroBackgroundIndex.value = 0;
        startHeroCarousel();
    },
    { immediate: true },
);

const resetFilters = () => {
    priceMin.value = '';
    priceMax.value = '';
    availabilityFilter.value = 'all';
    promoFilter.value = 'all';
    selectedCategory.value = '';
    searchQuery.value = '';
    sortOption.value = 'featured';
    currentPage.value = 1;
    if (typeof window !== 'undefined') {
        window.localStorage.removeItem(`mlk-store-filters:${props.company?.slug || 'default'}`);
    }
};

const restoreFilters = () => {
    if (typeof window === 'undefined') {
        filtersRestored.value = true;
        return;
    }
    const key = `mlk-store-filters:${props.company?.slug || 'default'}`;
    const raw = window.localStorage.getItem(key);
    if (!raw) {
        filtersRestored.value = true;
        return;
    }
    try {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') {
            searchQuery.value = parsed.searchQuery ?? searchQuery.value;
            selectedCategory.value = parsed.selectedCategory ?? selectedCategory.value;
            sortOption.value = parsed.sortOption ?? sortOption.value;
            priceMin.value = parsed.priceMin ?? priceMin.value;
            priceMax.value = parsed.priceMax ?? priceMax.value;
            availabilityFilter.value = parsed.availabilityFilter ?? availabilityFilter.value;
            promoFilter.value = parsed.promoFilter ?? promoFilter.value;
        }
    } catch (error) {
        window.localStorage.removeItem(key);
    } finally {
        filtersRestored.value = true;
    }
};

const persistFilters = () => {
    if (typeof window === 'undefined') {
        return;
    }
    const key = `mlk-store-filters:${props.company?.slug || 'default'}`;
    const payload = {
        searchQuery: searchQuery.value,
        selectedCategory: selectedCategory.value,
        sortOption: sortOption.value,
        priceMin: priceMin.value,
        priceMax: priceMax.value,
        availabilityFilter: availabilityFilter.value,
        promoFilter: promoFilter.value,
    };
    window.localStorage.setItem(key, JSON.stringify(payload));
};

const formatReviewDate = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return new Intl.DateTimeFormat(locale.value || undefined, { dateStyle: 'medium' }).format(date);
};

const loadProductReviews = async (product) => {
    reviewsLoading.value = false;
    if (!product || !props.company?.slug) {
        productReviews.value = [];
        return;
    }

    const reviewTargetId = product.id;
    const cached = reviewsCache.value[reviewTargetId];
    if (cached) {
        productReviews.value = cached;
        return;
    }

    if (Number(product.rating_count || 0) <= 0) {
        productReviews.value = [];
        return;
    }

    reviewsLoading.value = true;
    reviewsError.value = '';

    try {
        const response = await axios.get(
            route('public.store.product.reviews', { slug: props.company.slug, product: reviewTargetId }),
            { headers: { Accept: 'application/json' } },
        );
        const reviews = response.data?.reviews || [];
        reviewsCache.value = { ...reviewsCache.value, [reviewTargetId]: reviews };
        if (selectedProduct.value?.id === reviewTargetId) {
            productReviews.value = reviews;
        }
    } catch (error) {
        if (selectedProduct.value?.id === reviewTargetId) {
            reviewsError.value = t('public_store.reviews.load_error');
            productReviews.value = [];
        }
    } finally {
        if (selectedProduct.value?.id === reviewTargetId) {
            reviewsLoading.value = false;
        }
    }
};

const openCart = () => {
    cartVisible.value = true;
    checkoutError.value = '';
};

const closeCart = () => {
    cartVisible.value = false;
};

const updateCartState = (payload) => {
    if (payload?.cart) {
        cartData.value = payload.cart;
    }
};

const handleCartError = () => {
    cartError.value = t('public_store.cart_error');
};

const addToCart = async (product, quantity = 1) => {
    if (!product || cartBusy.value || !props.company?.slug) {
        return;
    }
    if (Number(product.stock || 0) <= 0) {
        return;
    }

    cartBusy.value = true;
    cartError.value = '';
    try {
        const response = await axios.post(
            route('public.store.cart.add', { slug: props.company.slug }),
            { product_id: product.id, quantity },
            { headers: { Accept: 'application/json' } },
        );
        updateCartState(response.data);
        triggerCartPulse();
        emitToast(t('public_store.cart.added'));
        openCart();
    } catch (error) {
        handleCartError();
    } finally {
        cartBusy.value = false;
    }
};

const handleCardAdd = (payload) => {
    if (!payload) {
        return;
    }
    if (payload.product) {
        addToCart(payload.product, payload.quantity || 1);
        return;
    }
    addToCart(payload);
};

const setQuantity = async (product, quantity) => {
    if (!product || cartBusy.value || !props.company?.slug) {
        return;
    }

    const maxStock = Number(product?.stock || 0);
    let next = Math.max(0, quantity);
    if (maxStock > 0) {
        next = Math.min(next, maxStock);
    }

    cartBusy.value = true;
    cartError.value = '';

    try {
        const response = await axios.patch(
            route('public.store.cart.update', { slug: props.company.slug, product: product.id }),
            { quantity: next },
            { headers: { Accept: 'application/json' } },
        );
        updateCartState(response.data);
        triggerCartPulse();
    } catch (error) {
        handleCartError();
    } finally {
        cartBusy.value = false;
    }
};

const increment = (product) => {
    const next = cartQuantity(product.id) + 1;
    setQuantity(product, next);
};

const decrement = (product) => {
    const next = cartQuantity(product.id) - 1;
    setQuantity(product, next);
};

onMounted(() => {
    restoreFilters();
    window.setTimeout(() => {
        pageLoading.value = false;
    }, 350);
});

onBeforeUnmount(() => {
    clearHeroCarousel();
});

const incrementItem = (item) => {
    setQuantity({ id: item.product_id, stock: item.stock }, Number(item.quantity || 0) + 1);
};

const decrementItem = (item) => {
    setQuantity({ id: item.product_id, stock: item.stock }, Number(item.quantity || 0) - 1);
};

const removeItem = (item) => {
    setQuantity({ id: item.product_id, stock: item.stock }, 0);
};

const clearCart = async () => {
    if (!props.company?.slug || cartBusy.value) {
        return;
    }
    cartBusy.value = true;
    cartError.value = '';
    try {
        const response = await axios.delete(
            route('public.store.cart.clear', { slug: props.company.slug }),
            { headers: { Accept: 'application/json' } },
        );
        updateCartState(response.data);
    } catch (error) {
        handleCartError();
    } finally {
        cartBusy.value = false;
    }
};

const submitCheckout = async () => {
    if (!cartItems.value.length || checkoutProcessing.value || !props.company?.slug) {
        return;
    }

    checkoutProcessing.value = true;
    checkoutErrors.value = {};
    checkoutError.value = '';

    try {
        const response = await axios.post(
            route('public.store.checkout', { slug: props.company.slug }),
            checkoutForm.value,
            { headers: { Accept: 'application/json' } },
        );

        const redirectUrl = response.data?.redirect_url;
        if (redirectUrl) {
            window.location.href = redirectUrl;
            return;
        }
        window.location.reload();
    } catch (error) {
        if (error?.response?.status === 422) {
            checkoutErrors.value = error.response.data?.errors || {};
            checkoutError.value = error.response.data?.message || '';
            openCart();
            return;
        }
        checkoutError.value = t('public_store.checkout_error');
    } finally {
        checkoutProcessing.value = false;
    }
};

const heroPrimaryAction = () => {
    scrollToCatalog();
};

const heroSecondaryAction = () => {
    if (heroProduct.value) {
        addToCart(heroProduct.value);
        return;
    }
    scrollToCatalog();
};
</script>

<template>
    <Head :title="pageTitle" />

    <div class="min-h-screen bg-slate-50 text-slate-900">
        <FlashToaster />
        <div class="fixed inset-x-0 top-0 z-50">
            <header
                :style="headerStyle"
                :class="['border-b text-white', headerIsCustom ? 'border-white/10' : 'border-slate-800 bg-slate-900']"
            >
                <div class="mx-auto w-full px-4 sm:px-6 lg:px-10">
                <div class="flex items-center gap-4 py-2 sm:py-3">
                    <Link :href="route('welcome')" class="flex items-center gap-3">
                        <div :class="['h-9 w-9 overflow-hidden rounded-sm border sm:h-10 sm:w-10', headerIsCustom ? 'border-white/20 bg-white/10' : 'border-slate-700 bg-slate-800']">
                            <img
                                v-if="company?.logo_url"
                                :src="company.logo_url"
                                :alt="companyName"
                                class="h-full w-full object-cover"
                                loading="lazy"
                                decoding="async"
                            >
                            <div v-else class="flex h-full w-full items-center justify-center text-sm font-semibold text-slate-200">
                                {{ companyName.charAt(0) }}
                            </div>
                        </div>
                        <div class="hidden flex-col sm:flex">
                            <span class="text-sm font-semibold text-white">{{ companyName }}</span>
                            <span class="text-[11px] text-slate-400">/store/{{ company?.slug }}</span>
                        </div>
                    </Link>

                    <div class="hidden flex-1 lg:block">
                        <FloatingInput
                            v-model="searchQuery"
                            type="search"
                            :label="t('public_store.filters.search')"
                            :autocomplete="'off'"
                        />
                    </div>

                    <div class="ml-auto flex items-center gap-3 sm:gap-4">
                        <div class="hidden items-center gap-3 text-xs font-semibold text-slate-200 md:flex">
                            <template v-if="isAuthenticated">
                                <span>{{ authUser?.name || authUser?.email }}</span>
                                <Link v-if="portalLink" :href="portalLink" class="text-emerald-300 hover:text-emerald-200">
                                    {{ authAccount?.is_client ? 'Portal' : 'Dashboard' }}
                                </Link>
                            </template>
                            <template v-else>
                                <Link :href="route('login')" class="hover:text-white">
                                    {{ t('public_store.actions.login') }}
                                </Link>
                                <Link :href="route('register')" class="text-emerald-300 hover:text-emerald-200">
                                    {{ t('public_store.actions.register') }}
                                </Link>
                            </template>
                        </div>
                        <button
                            type="button"
                            :class="[
                                'relative inline-flex items-center justify-center rounded-sm border px-2.5 py-2 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400',
                                headerIsCustom ? 'border-white/20 bg-white/10 hover:border-white/40' : 'border-slate-700 bg-slate-800 hover:border-slate-600',
                            ]"
                            @click="openCart"
                            aria-label="Open cart"
                        >
                            <span class="sr-only">{{ t('public_store.cart.title') }}</span>
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="9" cy="21" r="1"></circle>
                                <circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.5a2 2 0 0 0 2-1.6L23 6H6"></path>
                            </svg>
                            <span
                                class="absolute -right-1 -top-1 rounded-sm bg-emerald-500 px-1.5 py-0.5 text-[10px] font-semibold text-white transition-transform"
                                :class="cartPulse ? 'scale-110 ring-2 ring-emerald-200' : 'scale-100'"
                            >
                                {{ cartItemCount }}
                            </span>
                        </button>
                    </div>
                </div>

                <div class="pb-3 lg:hidden">
                    <FloatingInput
                        v-model="searchQuery"
                        type="search"
                        :label="t('public_store.filters.search')"
                        :autocomplete="'off'"
                    />
                </div>
                </div>
            </header>

            <nav class="border-b border-slate-200 bg-white">
                <div class="mx-auto flex w-full flex-col gap-2 px-4 py-2 sm:px-6 lg:flex-row lg:items-center lg:px-10">
                <CategoryChips
                    :categories="categories"
                    :selected="selectedCategory"
                    :all-label="t('public_store.filters.all')"
                    @select="setCategoryAndScroll"
                />
                <div class="flex flex-wrap gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400 lg:ml-auto">
                    <button type="button" class="hover:text-slate-600" @click="scrollToSection('best-sellers')">
                        {{ t('public_store.sections.best_sellers') }}
                    </button>
                    <button type="button" class="hover:text-slate-600" @click="scrollToSection('promotions')">
                        {{ t('public_store.sections.promotions') }}
                    </button>
                    <button type="button" class="hover:text-slate-600" @click="scrollToSection('new-arrivals')">
                        {{ t('public_store.sections.new_arrivals') }}
                    </button>
                    <button type="button" class="hover:text-slate-600" @click="scrollToCatalog">
                        {{ t('public_store.sections.catalog') }}
                    </button>
                </div>
                </div>
            </nav>
        </div>

        <main class="pt-[140px] sm:pt-[132px] lg:pt-[120px]">
            <section class="relative overflow-hidden py-10 lg:py-14">
                <Transition name="hero-fade" mode="out-in">
                    <div
                        v-if="heroBackgrounds.length"
                        :key="heroBackgroundIndex"
                        class="absolute inset-0 bg-cover bg-center"
                        :style="heroBackgroundStyle"
                    ></div>
                </Transition>
                <div
                    v-if="heroBackgrounds.length"
                    class="absolute inset-0 bg-gradient-to-r from-white/90 via-white/70 to-white/90"
                ></div>
                <div class="relative mx-auto grid w-full gap-8 px-4 sm:px-6 lg:grid-cols-[1.1fr_0.9fr] lg:px-10">
                    <div class="space-y-6">
                        <div class="space-y-3">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                {{ t('public_store.hero.eyebrow') }}
                            </span>
                            <h1 class="text-3xl font-semibold text-slate-900 md:text-4xl">
                                {{ t('public_store.hero.headline', { company: companyName }) }}
                            </h1>
                            <p class="text-base text-slate-600">
                                {{ t('public_store.hero.subheadline') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button
                                type="button"
                                class="rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                @click="heroPrimaryAction"
                            >
                                {{ t('public_store.actions.view_catalog') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                @click="heroSecondaryAction"
                            >
                                {{ heroProduct ? t('public_store.actions.add_to_cart') : t('public_store.actions.explore') }}
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="(stat, index) in heroStats"
                                :key="index"
                                class="rounded-sm border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600"
                            >
                                {{ stat }}
                            </span>
                        </div>
                    </div>

                    <div v-if="heroProduct" class="space-y-4">
                        <ProductCard
                            :product="heroProduct"
                            variant="featured"
                            :loading="pageLoading"
                            :badges="heroBadges"
                            :stock-label="stockLabel(heroProduct)"
                            :stock-tone="stockTone(heroProduct)"
                            :description-fallback="t('public_store.empty_description')"
                            :rating-empty-label="t('public_store.reviews.empty')"
                            :cta-label="t('public_store.actions.add_to_cart')"
                            :view-label="t('public_store.actions.view_product')"
                            :show-view="true"
                            @open="openProductDetails"
                            @add="handleCardAdd"
                            @view="openProductDetails"
                        />
                    </div>
                </div>
            </section>

            <ProductSection
                section-id="best-sellers"
                :title="t('public_store.sections.best_sellers')"
                :subtitle="t('public_store.subtitle')"
                :action-label="t('public_store.actions.view_catalog')"
                :empty-label="t('public_store.empty_collection')"
                :products="bestSellers"
                :loading="pageLoading"
                :description-fallback="t('public_store.empty_description')"
                :rating-empty-label="t('public_store.reviews.empty')"
                :cta-label="t('public_store.actions.add_to_cart')"
                :view-label="t('public_store.actions.view_product')"
                :get-badges="badgeList"
                :get-stock-label="stockLabel"
                :get-stock-tone="stockTone"
                :get-quantity="(product) => cartQuantity(product.id)"
                :show-quick-add="true"
                card-variant="compact"
                @action="scrollToCatalog"
                @open="openProductDetails"
                @add="handleCardAdd"
                @view="openProductDetails"
                @increment="increment"
                @decrement="decrement"
            />

            <ProductSection
                section-id="promotions"
                :title="t('public_store.sections.promotions')"
                :subtitle="t('public_store.promotions_hint')"
                :action-label="t('public_store.actions.view_catalog')"
                :empty-label="t('public_store.empty_collection')"
                :products="promotions"
                :loading="pageLoading"
                :description-fallback="t('public_store.empty_description')"
                :rating-empty-label="t('public_store.reviews.empty')"
                :cta-label="t('public_store.actions.add_to_cart')"
                :view-label="t('public_store.actions.view_product')"
                :get-badges="badgeList"
                :get-stock-label="stockLabel"
                :get-stock-tone="stockTone"
                :get-quantity="(product) => cartQuantity(product.id)"
                :show-quick-add="true"
                card-variant="compact"
                @action="scrollToCatalog"
                @open="openProductDetails"
                @add="handleCardAdd"
                @view="openProductDetails"
                @increment="increment"
                @decrement="decrement"
            />

            <ProductSection
                section-id="new-arrivals"
                :title="t('public_store.sections.new_arrivals')"
                :subtitle="t('public_store.subtitle')"
                :action-label="t('public_store.actions.view_catalog')"
                :empty-label="t('public_store.empty_collection')"
                :products="newArrivals"
                :loading="pageLoading"
                :description-fallback="t('public_store.empty_description')"
                :rating-empty-label="t('public_store.reviews.empty')"
                :cta-label="t('public_store.actions.add_to_cart')"
                :view-label="t('public_store.actions.view_product')"
                :get-badges="badgeList"
                :get-stock-label="stockLabel"
                :get-stock-tone="stockTone"
                :get-quantity="(product) => cartQuantity(product.id)"
                :show-quick-add="true"
                card-variant="compact"
                @action="scrollToCatalog"
                @open="openProductDetails"
                @add="handleCardAdd"
                @view="openProductDetails"
                @increment="increment"
                @decrement="decrement"
            />

            <section class="py-8" id="catalog">
                <div class="mx-auto w-full space-y-6 px-4 sm:px-6 lg:px-10">
                    <SectionHeader
                        :title="t('public_store.sections.catalog')"
                        :subtitle="t('public_store.subtitle')"
                    >
                        <template #actions>
                            <span class="rounded-sm border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                                {{ t('public_store.catalog.results', { count: sortedProducts.length }) }}
                            </span>
                        </template>
                    </SectionHeader>

                    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                        <div class="order-2 space-y-4 lg:order-1">
                            <CategoryChips
                                :categories="categories"
                                :selected="selectedCategory"
                                :all-label="t('public_store.filters.all')"
                                @select="setCategory"
                            />
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                    {{ t('public_store.sort.label') }}
                                </span>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="option in sortOptions"
                                        :key="option.value"
                                        type="button"
                                        class="rounded-sm border px-2.5 py-1 text-xs font-semibold transition"
                                        :class="sortOption === option.value
                                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                                            : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-white'"
                                        @click="sortOption = option.value"
                                    >
                                        {{ option.label }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="pageLoading" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                <ProductCard
                                    v-for="n in 8"
                                    :key="`catalog-skeleton-${n}`"
                                    :product="{ id: `catalog-skeleton-${n}`, name: 'Loading' }"
                                    :loading="true"
                                />
                            </div>
                            <div v-else-if="sortedProducts.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                <ProductCard
                                    v-for="product in pagedProducts"
                                    :key="product.id"
                                    :product="product"
                                    :badges="badgeList(product)"
                                    :stock-label="stockLabel(product)"
                                    :stock-tone="stockTone(product)"
                                    :description-fallback="t('public_store.empty_description')"
                                    :rating-empty-label="t('public_store.reviews.empty')"
                                    :cta-label="t('public_store.actions.add_to_cart')"
                                    :view-label="t('public_store.actions.view_product')"
                                    :quantity="cartQuantity(product.id)"
                                    :show-quick-add="true"
                                    @open="openProductDetails"
                                    @add="handleCardAdd"
                                    @view="openProductDetails"
                                    @increment="increment"
                                    @decrement="decrement"
                                />
                            </div>
                            <div
                                v-if="!pageLoading && sortedProducts.length > pageSize"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600"
                            >
                                <button
                                    type="button"
                                    class="rounded-sm border border-slate-200 px-2.5 py-1 font-semibold text-slate-600 hover:border-slate-300 hover:text-slate-800 disabled:opacity-50"
                                    :disabled="currentPage <= 1"
                                    @click="currentPage = Math.max(1, currentPage - 1)"
                                >
                                    {{ t('public_store.pagination.previous') }}
                                </button>
                                <span class="font-semibold">
                                    {{ t('public_store.pagination.page', { current: currentPage, total: totalPages }) }}
                                </span>
                                <button
                                    type="button"
                                    class="rounded-sm border border-slate-200 px-2.5 py-1 font-semibold text-slate-600 hover:border-slate-300 hover:text-slate-800 disabled:opacity-50"
                                    :disabled="currentPage >= totalPages"
                                    @click="currentPage = Math.min(totalPages, currentPage + 1)"
                                >
                                    {{ t('public_store.pagination.next') }}
                                </button>
                            </div>
                            <p v-else class="text-sm text-slate-500">
                                {{ t('public_store.empty') }}
                            </p>
                        </div>

                        <aside class="order-1 lg:order-2">
                            <div class="sticky top-6 space-y-3 rounded-sm border border-slate-200 bg-white p-3">
                                <FloatingInput
                                    v-model="searchQuery"
                                    type="search"
                                    :label="t('public_store.filters.search')"
                                    :autocomplete="'off'"
                                />
                                <FloatingSelect
                                    v-model="selectedCategory"
                                    :label="t('public_store.filters.category')"
                                    :options="categoryOptions"
                                    option-value="value"
                                    option-label="label"
                                />
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <FloatingInput
                                        v-model="priceMin"
                                        type="number"
                                        :label="t('public_store.filters.price_min')"
                                    />
                                    <FloatingInput
                                        v-model="priceMax"
                                        type="number"
                                        :label="t('public_store.filters.price_max')"
                                    />
                                </div>
                                <FloatingSelect
                                    v-model="availabilityFilter"
                                    :label="t('public_store.filters.availability')"
                                    :options="availabilityOptions"
                                    option-value="value"
                                    option-label="label"
                                />
                                <FloatingSelect
                                    v-model="promoFilter"
                                    :label="t('public_store.filters.promotions')"
                                    :options="promoOptions"
                                    option-value="value"
                                    option-label="label"
                                />
                                <FloatingSelect
                                    v-model="sortOption"
                                    :label="t('public_store.filters.sort')"
                                    :options="sortOptions"
                                    option-value="value"
                                    option-label="label"
                                />
                                <button
                                    type="button"
                                    class="rounded-sm border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-500 hover:border-slate-300 hover:text-slate-700"
                                    @click="resetFilters"
                                >
                                    {{ t('public_store.filters.reset') }}
                                </button>
                                <div class="rounded-sm border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-500">
                                    {{ t('public_store.catalog.results', { count: sortedProducts.length }) }}
                                </div>
                            </div>
                        </aside>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-slate-200 bg-white py-6">
            <div class="mx-auto w-full px-4 text-sm text-slate-500 sm:px-6 lg:px-10">
                {{ t('public_store.hints.login_to_order') }}
            </div>
        </footer>

    </div>

    <div v-if="cartVisible" class="fixed inset-0 z-[60] bg-slate-900/50" @click.self="closeCart">
        <div class="fixed inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-xl">
            <header class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">{{ t('public_store.cart.title') }}</h3>
                    <p class="text-xs text-slate-500">{{ t('public_store.cart.item_count', { count: cartItemCount }) }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-sm border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 hover:border-slate-300"
                    @click="closeCart"
                    aria-label="Close cart"
                >
                    x
                </button>
            </header>

            <div class="flex-1 overflow-y-auto">
                <div class="space-y-4 px-5 py-4">
                    <div v-if="cartItems.length" class="space-y-4">
                        <div v-for="item in cartItems" :key="item.product_id" class="flex gap-3 rounded-sm border border-slate-200 p-3">
                            <div class="h-16 w-16 flex-shrink-0 overflow-hidden rounded-sm bg-slate-100">
                                <img
                                    v-if="item.image_url"
                                    :src="item.image_url"
                                    :alt="item.name"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <div v-else class="flex h-full w-full items-center justify-center text-sm font-semibold text-slate-300">
                                    {{ item.name.charAt(0) }}
                                </div>
                            </div>
                            <div class="flex-1 space-y-1">
                                <div class="text-sm font-semibold text-slate-800">{{ item.name }}</div>
                                <div class="text-xs text-slate-400">{{ item.sku || t('public_store.cart.sku_fallback') }}</div>
                                <div class="flex items-center gap-2 text-xs text-slate-500">
                                    <span>{{ formatCurrency(item.price) }}</span>
                                    <span v-if="item.base_price && item.base_price > item.price" class="line-through">
                                        {{ formatCurrency(item.base_price) }}
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="inline-flex items-center gap-2 rounded-sm border border-slate-200 px-2 py-1">
                                        <button type="button" class="text-xs" @click="decrementItem(item)">-</button>
                                        <span class="text-xs font-semibold">{{ item.quantity }}</span>
                                        <button type="button" class="text-xs" @click="incrementItem(item)">+</button>
                                    </div>
                                    <button type="button" class="text-xs font-semibold text-rose-500" @click="removeItem(item)">
                                        {{ t('public_store.cart.remove') }}
                                    </button>
                                </div>
                            </div>
                            <div class="text-sm font-semibold text-slate-700">
                                {{ formatCurrency(item.line_total) }}
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-slate-500">{{ t('public_store.cart.empty') }}</p>

                    <p v-if="cartError" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-600">
                        {{ cartError }}
                    </p>
                    <p v-if="checkoutError" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-600">
                        {{ checkoutError }}
                    </p>
                    <p v-if="isInternalUser" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        {{ t('public_store.cart.internal_notice') }}
                    </p>
                </div>

                <div v-if="cartItems.length" class="space-y-4 border-t border-slate-200 px-5 py-4">
                    <div class="space-y-2 text-sm text-slate-600">
                        <div class="flex items-center justify-between">
                            <span>{{ t('public_store.cart.subtotal') }}</span>
                            <span>{{ formatCurrency(cartSubtotal) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('public_store.cart.taxes') }}</span>
                            <span>{{ formatCurrency(cartTaxes) }}</span>
                        </div>
                        <div v-if="deliveryFee" class="flex items-center justify-between">
                            <span>{{ t('public_store.cart.delivery_fee') }}</span>
                            <span>{{ formatCurrency(deliveryFee) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-base font-semibold text-slate-900">
                            <span>{{ t('public_store.cart.total') }}</span>
                            <span>{{ formatCurrency(checkoutTotal) }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="text-xs font-semibold text-slate-500 hover:text-slate-700"
                        @click="clearCart"
                    >
                        {{ t('public_store.cart.clear') }}
                    </button>
                </div>

                <div v-if="cartItems.length" class="space-y-4 border-t border-slate-200 px-5 py-4">
                    <div>
                        <h4 class="text-base font-semibold text-slate-900">{{ t('public_store.cart.checkout') }}</h4>
                        <p class="text-xs text-slate-500">{{ t('public_store.cart.checkout_hint') }}</p>
                    </div>
                    <div class="space-y-4 rounded-sm border border-slate-200 bg-white p-4">
                        <div class="flex items-center justify-between">
                            <h5 class="text-sm font-semibold text-slate-900">{{ t('public_store.fulfillment.title') }}</h5>
                            <span v-if="showFulfillmentChoice" class="text-[11px] font-semibold text-slate-400">
                                {{ t('public_store.fulfillment.subtitle') }}
                            </span>
                        </div>
                        <div v-if="fulfillmentUnavailable" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                            {{ t('public_store.fulfillment.unavailable') }}
                        </div>
                        <template v-else>
                            <div v-if="showFulfillmentChoice" class="space-y-2">
                                <button
                                    v-if="deliveryEnabled"
                                    type="button"
                                    class="flex w-full items-center justify-between rounded-sm border px-3 py-2 text-left text-sm transition"
                                    :class="fulfillmentMethod === 'delivery' ? 'border-emerald-400 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600 hover:border-slate-300'"
                                    @click="setFulfillmentMethod('delivery')"
                                >
                                    <span class="flex items-center gap-2">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="1" y="3" width="15" height="13" rx="2"></rect>
                                            <path d="M16 8h4l3 4v4h-7"></path>
                                            <circle cx="5.5" cy="18.5" r="1.5"></circle>
                                            <circle cx="18.5" cy="18.5" r="1.5"></circle>
                                        </svg>
                                        {{ t('public_store.fulfillment.delivery') }}
                                    </span>
                                    <span class="text-xs font-semibold">{{ formatCurrency(deliveryFeeAmount) }}</span>
                                </button>
                                <button
                                    v-if="pickupEnabled"
                                    type="button"
                                    class="flex w-full items-center justify-between rounded-sm border px-3 py-2 text-left text-sm transition"
                                    :class="fulfillmentMethod === 'pickup' ? 'border-emerald-400 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600 hover:border-slate-300'"
                                    @click="setFulfillmentMethod('pickup')"
                                >
                                    <span class="flex items-center gap-2">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 7h13v10H3z"></path>
                                            <path d="M16 10h4l2 3v4h-6"></path>
                                            <circle cx="7.5" cy="19" r="1.5"></circle>
                                            <circle cx="18.5" cy="19" r="1.5"></circle>
                                        </svg>
                                        {{ t('public_store.fulfillment.pickup') }}
                                    </span>
                                    <span class="text-xs font-semibold">
                                        {{ prepTime ? t('public_store.fulfillment.ready_in', { minutes: prepTime }) : t('public_store.fulfillment.ready') }}
                                    </span>
                                </button>
                            </div>
                            <div v-else class="flex items-center justify-between rounded-sm border border-slate-200 px-3 py-2 text-sm text-slate-600">
                                <span class="flex items-center gap-2">
                                    <svg v-if="fulfillmentMethod === 'delivery'" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="1" y="3" width="15" height="13" rx="2"></rect>
                                        <path d="M16 8h4l3 4v4h-7"></path>
                                        <circle cx="5.5" cy="18.5" r="1.5"></circle>
                                        <circle cx="18.5" cy="18.5" r="1.5"></circle>
                                    </svg>
                                    <svg v-else class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 7h13v10H3z"></path>
                                        <path d="M16 10h4l2 3v4h-6"></path>
                                        <circle cx="7.5" cy="19" r="1.5"></circle>
                                        <circle cx="18.5" cy="19" r="1.5"></circle>
                                    </svg>
                                    {{ fulfillmentMethod === 'delivery' ? t('public_store.fulfillment.delivery') : t('public_store.fulfillment.pickup') }}
                                </span>
                                <span class="text-xs font-semibold">
                                    {{ fulfillmentMethod === 'delivery'
                                        ? formatCurrency(deliveryFeeAmount)
                                        : (prepTime ? t('public_store.fulfillment.ready_in', { minutes: prepTime }) : t('public_store.fulfillment.ready')) }}
                                </span>
                            </div>
                            <div v-if="fulfillmentMethod === 'delivery'" class="space-y-3">
                                <FloatingInput
                                    v-model="checkoutForm.delivery_address"
                                    :label="t('public_store.fulfillment.delivery_address')"
                                />
                                <span v-if="checkoutErrors.delivery_address" class="text-xs text-rose-500">{{ checkoutErrors.delivery_address }}</span>
                                <FloatingTextarea
                                    v-model="checkoutForm.delivery_notes"
                                    :label="t('public_store.fulfillment.delivery_notes')"
                                />
                                <div v-if="deliveryZone" class="text-xs text-slate-500">
                                    {{ t('public_store.fulfillment.delivery_zone', { zone: deliveryZone }) }}
                                </div>
                                <DateTimePicker
                                    v-model="checkoutForm.scheduled_for"
                                    :label="t('public_store.fulfillment.scheduled_for')"
                                />
                            </div>
                            <div v-else-if="fulfillmentMethod === 'pickup'" class="space-y-3">
                                <div v-if="pickupAddress" class="text-xs text-slate-500">
                                    {{ t('public_store.fulfillment.pickup_address', { address: pickupAddress }) }}
                                </div>
                                <FloatingTextarea
                                    v-model="checkoutForm.pickup_notes"
                                    :label="t('public_store.fulfillment.pickup_notes')"
                                />
                                <DateTimePicker
                                    v-model="checkoutForm.scheduled_for"
                                    :label="t('public_store.fulfillment.pickup_time')"
                                />
                            </div>
                        </template>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <FloatingInput v-model="checkoutForm.name" type="text" :label="t('public_store.cart.name')" />
                            <span v-if="checkoutErrors.name" class="text-xs text-rose-500">{{ checkoutErrors.name }}</span>
                        </div>
                        <div class="space-y-1">
                            <FloatingInput v-model="checkoutForm.email" type="email" :label="t('public_store.cart.email')" />
                            <span v-if="checkoutErrors.email" class="text-xs text-rose-500">{{ checkoutErrors.email }}</span>
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <FloatingInput v-model="checkoutForm.phone" type="text" :label="t('public_store.cart.phone')" />
                            <span v-if="checkoutErrors.phone" class="text-xs text-rose-500">{{ checkoutErrors.phone }}</span>
                        </div>
                    </div>

                    <div class="space-y-3 rounded-sm border border-slate-200 bg-white p-4">
                        <h5 class="text-sm font-semibold text-slate-900">{{ t('public_store.notes.title') }}</h5>
                        <FloatingTextarea
                            v-model="checkoutForm.customer_notes"
                            :label="t('public_store.notes.customer_notes')"
                        />
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                            <input v-model="checkoutForm.substitution_allowed" type="checkbox" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                            {{ t('public_store.notes.substitution_allowed') }}
                        </label>
                        <FloatingTextarea
                            v-model="checkoutForm.substitution_notes"
                            :label="t('public_store.notes.substitution_notes')"
                        />
                    </div>
                    <button
                        type="button"
                        class="rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-emerald-300"
                        :disabled="checkoutProcessing || isInternalUser || fulfillmentUnavailable"
                        @click="submitCheckout"
                    >
                        {{ t('public_store.cart.submit') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <Modal :show="showProductDetails" @close="closeProductDetails" maxWidth="xl">
        <div v-if="selectedProduct" class="space-y-4 p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('public_store.product.details') }}</span>
                    <div class="text-xl font-semibold text-slate-900">{{ selectedProduct.name }}</div>
                    <div class="text-xs text-slate-400">{{ selectedProduct.sku || t('public_store.cart.sku_fallback') }}</div>
                </div>
                <button
                    type="button"
                    class="rounded-sm border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600"
                    @click="closeProductDetails"
                    aria-label="Close dialog"
                >
                    x
                </button>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <div class="relative overflow-hidden rounded-sm border border-slate-200 bg-slate-100">
                        <img
                            v-if="activeImageSrc"
                            :src="activeImageSrc"
                            :alt="selectedProduct.name"
                            class="h-56 w-full object-cover"
                            loading="lazy"
                            decoding="async"
                        >
                        <div v-else class="flex h-56 w-full items-center justify-center text-3xl font-semibold text-slate-300">
                            {{ selectedProduct.name.charAt(0) }}
                        </div>
                        <div v-if="badgeList(selectedProduct).length" class="absolute left-3 top-3 flex flex-wrap gap-2">
                            <Badge
                                v-for="badge in badgeList(selectedProduct)"
                                :key="badge.label"
                                :label="badge.label"
                                :tone="badge.tone"
                            />
                        </div>
                    </div>
                    <div v-if="productImages.length > 1" class="flex gap-2 overflow-x-auto">
                        <button
                            v-for="(image, index) in productImages"
                            :key="`${image}-${index}`"
                            type="button"
                            class="h-12 w-12 overflow-hidden rounded-sm border border-slate-200"
                            :class="{ 'ring-2 ring-emerald-400': image === activeImageSrc }"
                            @click="setActiveImage(image)"
                        >
                            <img :src="image" :alt="selectedProduct.name" class="h-full w-full object-cover" loading="lazy" decoding="async">
                        </button>
                    </div>
                </div>
                <div class="space-y-4">
                    <Price :current="priceMeta(selectedProduct).current" :original="priceMeta(selectedProduct).original" size="lg" />
                    <div class="grid gap-3 rounded-sm border border-slate-200 bg-white p-4 text-sm text-slate-600">
                        <div class="flex items-center justify-between">
                            <span>{{ t('public_store.product.category') }}</span>
                            <span class="font-semibold text-slate-800">{{ categoryLabel(selectedProduct) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('public_store.reviews.label') }}</span>
                            <span class="font-semibold text-slate-800">{{ selectedProduct.rating_count ? `${Number(selectedProduct.rating_avg || 0).toFixed(1)} / 5 (${selectedProduct.rating_count})` : t('public_store.reviews.empty') }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('public_store.product.stock') }}</span>
                            <span class="font-semibold text-slate-800">{{ stockLabel(selectedProduct) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ t('public_store.product.sku') }}</span>
                            <span class="font-semibold text-slate-800">{{ selectedProduct.sku || t('public_store.cart.sku_fallback') }}</span>
                        </div>
                    </div>
                    <p class="text-sm text-slate-600">
                        {{ selectedProduct.description || t('public_store.empty_description') }}
                    </p>
                    <div class="space-y-3 rounded-sm border border-slate-200 bg-white p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-sm font-semibold text-slate-900">{{ t('public_store.reviews.title') }}</h4>
                            <span class="text-xs font-semibold text-slate-500">
                                {{ selectedProduct.rating_count ? `${Number(selectedProduct.rating_avg || 0).toFixed(1)} / 5 (${selectedProduct.rating_count})` : t('public_store.reviews.empty') }}
                            </span>
                        </div>
                        <p v-if="reviewsLoading" class="text-xs text-slate-400">
                            {{ t('public_store.reviews.loading') }}
                        </p>
                        <p v-else-if="reviewsError" class="text-xs text-rose-500">
                            {{ reviewsError }}
                        </p>
                        <div v-else-if="productReviews.length" class="space-y-3">
                            <div
                                v-for="review in productReviews"
                                :key="review.id"
                                class="rounded-sm border border-slate-200 bg-slate-50 px-3 py-2"
                            >
                                <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                                    <span class="font-semibold text-slate-700">
                                        {{ review.author || t('public_store.reviews.anonymous') }}
                                    </span>
                                    <span class="font-semibold text-slate-700">{{ review.rating }} / 5</span>
                                </div>
                                <div v-if="review.title" class="mt-1 text-sm font-semibold text-slate-800">
                                    {{ review.title }}
                                </div>
                                <p v-if="review.comment" class="mt-1 text-xs text-slate-600">
                                    {{ review.comment }}
                                </p>
                                <div v-if="review.created_at" class="mt-1 text-[11px] text-slate-400">
                                    {{ formatReviewDate(review.created_at) }}
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-xs text-slate-500">
                            {{ t('public_store.reviews.empty') }}
                        </p>
                    </div>
                    <div v-if="relatedProducts.length" class="space-y-3">
                        <h4 class="text-sm font-semibold text-slate-900">{{ t('public_store.related.title') }}</h4>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <ProductCard
                                v-for="product in relatedProducts"
                                :key="`related-${product.id}`"
                                :product="product"
                                variant="compact"
                                :badges="badgeList(product)"
                                :stock-label="stockLabel(product)"
                                :stock-tone="stockTone(product)"
                                :description-fallback="t('public_store.empty_description')"
                                :rating-empty-label="t('public_store.reviews.empty')"
                                :view-label="t('public_store.actions.view_product')"
                                :quantity="cartQuantity(product.id)"
                                :show-quick-add="true"
                                @open="openProductDetails"
                                @view="openProductDetails"
                                @add="handleCardAdd"
                                @increment="increment"
                                @decrement="decrement"
                            />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button
                            v-if="cartQuantity(selectedProduct.id) === 0"
                            type="button"
                            class="rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                            :disabled="selectedProduct.stock <= 0"
                            @click="addToCart(selectedProduct)"
                        >
                            {{ t('public_store.actions.add_to_cart') }}
                        </button>
                        <div v-else class="flex items-center gap-2 rounded-sm border border-slate-200 px-3 py-2">
                            <button type="button" class="text-sm" @click="decrement(selectedProduct)">-</button>
                            <span class="text-sm font-semibold">{{ cartQuantity(selectedProduct.id) }}</span>
                            <button type="button" class="text-sm" @click="increment(selectedProduct)">+</button>
                        </div>
                        <button
                            v-if="cartItemCount"
                            type="button"
                            class="rounded-sm border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700"
                            @click="openCart"
                        >
                            {{ t('public_store.cart.title') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<style scoped>
.hero-fade-enter-active,
.hero-fade-leave-active {
    transition: opacity 0.8s ease;
}
.hero-fade-enter-from,
.hero-fade-leave-to {
    opacity: 0;
}
</style>
