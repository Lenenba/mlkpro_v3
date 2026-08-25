<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    KPI_VISIBILITY_CHANGE_EVENT,
    buildKpiVisibilityStorageKey,
    parseKpiVisibilityValue,
    readKpiVisibility,
    writeKpiVisibility,
} from '@/utils/kpiVisibility';

const props = defineProps({
    moduleKey: {
        type: String,
        required: true,
    },
    defaultVisible: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const { t } = useI18n();
const contentId = `module-kpis-${useId().replaceAll(':', '')}`;
const isVisible = ref(props.defaultVisible);
let isMounted = false;

const storageKey = computed(() => buildKpiVisibilityStorageKey({
    accountOwnerId: page.props.auth?.account?.owner_id
        ?? page.props.auth?.account?.company?.id,
    userId: page.props.auth?.user?.id,
    moduleKey: props.moduleKey,
}));

const browserStorage = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.localStorage;
    } catch {
        return null;
    }
};

const restoreVisibility = () => {
    isVisible.value = readKpiVisibility(
        browserStorage(),
        storageKey.value,
        props.defaultVisible,
    );
};

const broadcastVisibility = () => {
    if (typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(new CustomEvent(KPI_VISIBILITY_CHANGE_EVENT, {
        detail: {
            key: storageKey.value,
            visible: isVisible.value,
        },
    }));
};

const toggleVisibility = () => {
    isVisible.value = !isVisible.value;
    writeKpiVisibility(browserStorage(), storageKey.value, isVisible.value);
    broadcastVisibility();
};

const handleStorageChange = (event) => {
    if (event.key !== storageKey.value) {
        return;
    }

    const storage = browserStorage();
    if (event.storageArea && storage && event.storageArea !== storage) {
        return;
    }

    isVisible.value = parseKpiVisibilityValue(event.newValue, props.defaultVisible);
};

const handleVisibilityChange = (event) => {
    if (event.detail?.key === storageKey.value) {
        isVisible.value = Boolean(event.detail.visible);
    }
};

watch(storageKey, () => {
    if (isMounted) {
        restoreVisibility();
    }
});

onMounted(() => {
    isMounted = true;
    restoreVisibility();
    window.addEventListener('storage', handleStorageChange);
    window.addEventListener(KPI_VISIBILITY_CHANGE_EVENT, handleVisibilityChange);
});

onBeforeUnmount(() => {
    isMounted = false;
    window.removeEventListener('storage', handleStorageChange);
    window.removeEventListener(KPI_VISIBILITY_CHANGE_EVENT, handleVisibilityChange);
});
</script>

<template>
    <section class="min-w-0" :aria-label="t('kpi_visibility.title')">
        <div
            class="flex min-w-0 items-center justify-end"
            :class="isVisible ? 'mb-2' : ''"
        >
            <button
                type="button"
                class="inline-flex min-h-11 items-center gap-1.5 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-600 shadow-sm hover:bg-stone-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                :aria-expanded="String(isVisible)"
                :aria-controls="contentId"
                @click="toggleVisibility"
            >
                <svg
                    v-if="isVisible"
                    class="size-4 shrink-0"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path d="m2 2 20 20" />
                    <path d="M6.7 6.7C4.7 8.1 3.2 10 2.5 12c1.5 4.1 5.2 7 9.5 7 1.5 0 2.9-.3 4.1-.9" />
                    <path d="M10.7 5.1c.4-.1.9-.1 1.3-.1 4.3 0 8 2.9 9.5 7a10.8 10.8 0 0 1-2.1 3.4" />
                    <path d="M14.1 14.1a3 3 0 0 1-4.2-4.2" />
                </svg>
                <svg
                    v-else
                    class="size-4 shrink-0"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path d="M2.5 12c1.5-4.1 5.2-7 9.5-7s8 2.9 9.5 7c-1.5 4.1-5.2 7-9.5 7s-8-2.9-9.5-7Z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
                <span>
                    {{ t(isVisible ? 'kpi_visibility.hide' : 'kpi_visibility.show') }}
                </span>
            </button>
        </div>

        <div :id="contentId" v-show="isVisible">
            <slot />
        </div>
    </section>
</template>
