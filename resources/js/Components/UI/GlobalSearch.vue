<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import { isFeatureEnabled } from '@/utils/features';

const page = usePage();
const { t } = useI18n();

const isOpen = ref(false);
const query = ref('');
const groups = ref([]);
const loading = ref(false);
const error = ref('');
const inputRef = ref(null);
let debounceTimer;

const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showServices = computed(() => companyType.value !== 'products');
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const isClient = computed(() => Boolean(page.props.auth?.account?.is_client));
const canSearch = computed(() => !isClient.value);
const teamPermissions = computed(() => page.props.auth?.account?.team?.permissions || []);
const teamRole = computed(() => page.props.auth?.account?.team?.role || null);
const isSeller = computed(() => teamRole.value === 'seller');
const featureFlags = computed(() => page.props.auth?.account?.features || {});
const hasFeature = (key) => isFeatureEnabled(featureFlags.value, key);
const canSales = computed(() =>
    isOwner.value || teamPermissions.value.includes('sales.manage') || teamPermissions.value.includes('sales.pos')
);

const quickActions = computed(() => {
    if (isClient.value || isSeller.value) {
        return [];
    }

    const actions = [];
    const openOverlay = (selector) => {
        if (window.HSOverlay && selector) {
            window.HSOverlay.open(selector);
        }
    };

    if (!isSeller.value && ((isOwner.value && showServices.value) || (companyType.value === 'products' && hasFeature('sales') && canSales.value))) {
        actions.push({
            id: 'customer',
            label: t('quick_create.customer'),
            action: () => openOverlay('#hs-quick-create-customer'),
        });
        if (hasFeature('services') && showServices.value && isOwner.value) {
            actions.push({
                id: 'service',
                label: t('quick_create.service'),
                action: () => openOverlay('#hs-quick-create-service'),
            });
        }
        if (hasFeature('requests') && showServices.value && isOwner.value) {
            actions.push({
                id: 'request',
                label: t('quick_create.request'),
                action: () => openOverlay('#hs-quick-create-request'),
            });
        }
        if (hasFeature('quotes') && showServices.value && isOwner.value) {
            actions.push({
                id: 'quote',
                label: t('quick_create.quote'),
                action: () => openOverlay('#hs-quick-create-quote'),
            });
        }
    }

    if (isOwner.value && hasFeature('products')) {
        actions.push({
            id: 'product',
            label: t('quick_create.product'),
            action: () => openOverlay('#hs-quick-create-product'),
        });
    }

    return actions;
});

const hasResults = computed(() =>
    Array.isArray(groups.value) && groups.value.some((group) => (group.items || []).length > 0)
);

const openPalette = async () => {
    if (isOpen.value) {
        return;
    }
    isOpen.value = true;
    await nextTick();
    if (inputRef.value) {
        inputRef.value.focus();
    }
};

const closePalette = () => {
    isOpen.value = false;
    query.value = '';
    groups.value = [];
    error.value = '';
};

const handleKeydown = (event) => {
    if (!canSearch.value) {
        return;
    }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        openPalette();
    }
    if (event.key === 'Escape' && isOpen.value) {
        event.preventDefault();
        closePalette();
    }
};

const fetchResults = async () => {
    const term = query.value.trim();
    if (term.length < 2) {
        groups.value = [];
        error.value = '';
        return;
    }

    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(route('global.search'), {
            params: { q: term },
        });
        groups.value = Array.isArray(data?.groups) ? data.groups : [];
    } catch (err) {
        error.value = 'error';
    } finally {
        loading.value = false;
    }
};

watch(query, () => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(fetchResults, 250);
});

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown);
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div>
        <button
            v-if="canSearch"
            type="button"
            class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-600 shadow-sm hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
            @click="openPalette"
        >
            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7" />
                <path d="m21 21-4.3-4.3" />
            </svg>
            <span class="hidden sm:inline">{{ t('global_search.open') }}</span>
            <span class="hidden sm:inline text-xs text-stone-400">{{ t('global_search.hint') }}</span>
        </button>

        <Teleport v-if="canSearch" to="body">
            <div v-if="isOpen" class="fixed inset-0 z-[90]">
                <div class="absolute inset-0 bg-black/40" @click="closePalette"></div>
                <div
                    class="relative mx-auto mt-24 w-full max-w-2xl rounded-sm border border-stone-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-center gap-2 border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                        <svg class="size-4 text-stone-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="7" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                        <input
                            ref="inputRef"
                            v-model="query"
                            type="text"
                            class="w-full bg-transparent text-sm text-stone-700 outline-none placeholder:text-stone-400 dark:text-neutral-200"
                            :placeholder="t('global_search.placeholder')"
                        />
                        <button
                            type="button"
                            class="text-xs text-stone-400 hover:text-stone-600 dark:text-neutral-400 dark:hover:text-neutral-200"
                            @click="closePalette"
                        >
                            Esc
                        </button>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto px-4 py-3">
                        <div v-if="quickActions.length" class="mb-4">
                            <div class="mb-2 text-xs font-semibold uppercase text-stone-400">
                                {{ t('global_search.quick_actions') }}
                            </div>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <button
                                    v-for="action in quickActions"
                                    :key="action.id"
                                    type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-left text-sm text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="() => { action.action(); closePalette(); }"
                                >
                                    {{ action.label }}
                                </button>
                            </div>
                        </div>

                        <div v-if="loading" class="py-4 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('global_search.loading') }}
                        </div>
                        <div v-else-if="error" class="py-4 text-sm text-red-600">
                            {{ t('global_search.no_results') }}
                        </div>
                        <div v-else-if="hasResults">
                            <div v-for="group in groups" :key="group.type" class="mb-4">
                                <div class="mb-2 text-xs font-semibold uppercase text-stone-400">
                                    {{ t(`global_search.groups.${group.type}`) }}
                                </div>
                                <div class="space-y-2">
                                    <Link
                                        v-for="item in group.items"
                                        :key="`${group.type}-${item.id}`"
                                        :href="item.url"
                                        class="block rounded-sm border border-stone-200 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="closePalette"
                                    >
                                        <div class="font-medium">{{ item.title }}</div>
                                        <div v-if="item.subtitle" class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ item.subtitle }}
                                        </div>
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <div v-else-if="query.trim().length >= 2" class="py-4 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('global_search.no_results') }}
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
