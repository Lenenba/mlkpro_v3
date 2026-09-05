<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import { useFloatingMenu } from '@/Composables/useFloatingMenu';

const props = defineProps({
    source: {
        type: Object,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    triggerVariant: {
        type: String,
        default: 'trailing',
        validator: (value) => ['separator', 'trailing'].includes(value),
    },
});

const emit = defineEmits(['select']);
const { t } = useI18n();
const componentId = useId().replaceAll(':', '');
const dialogId = `breadcrumb-entities-${componentId}`;
const searchId = `breadcrumb-entities-search-${componentId}`;
const query = ref('');
const entities = ref([]);
const isLoading = ref(false);
const hasLoaded = ref(false);
const hasError = ref(false);
const inputRef = ref(null);
let debounceTimer = null;
let activeRequest = null;
let requestSequence = 0;
let stopNavigationListener = null;

const sourceHref = computed(() => (
    typeof props.source?.href === 'string'
        ? props.source.href.trim()
        : ''
));

const normalizeEntity = (item, index) => {
    if (!item || typeof item !== 'object') {
        return null;
    }

    const id = item.id ?? item.key ?? item.value ?? index;
    const label = item.label ?? item.name ?? item.title ?? item.reference ?? item.number;
    const href = typeof (item.href ?? item.url) === 'string'
        ? (item.href ?? item.url).trim()
        : '';
    const subtitle = item.subtitle ?? item.description ?? item.meta ?? '';

    if (!label || !href) {
        return null;
    }

    return {
        ...item,
        id,
        key: item.key ?? id,
        label: String(label),
        href,
        subtitle: ['string', 'number'].includes(typeof subtitle) ? String(subtitle) : '',
        current: Boolean(item.current)
            || (props.source?.currentId !== undefined
                && props.source?.currentId !== null
                && String(id) === String(props.source.currentId))
            || (props.source?.currentKey !== undefined
                && props.source?.currentKey !== null
                && String(item.key ?? id) === String(props.source.currentKey)),
    };
};

const normalizePayload = (payload) => {
    const body = payload?.data && !Array.isArray(payload.data)
        ? payload.data
        : payload;
    const candidates = Array.isArray(body)
        ? body
        : [body?.items, body?.data, body?.results].find(Array.isArray) || [];

    return candidates
        .map(normalizeEntity)
        .filter(Boolean);
};

const {
    isOpen,
    toggleRef,
    menuRef,
    menuStyle,
    openMenu,
    closeMenu,
    updatePosition,
} = useFloatingMenu({ align: 'start', padding: 12, offset: 6 });

const resultElements = () => [
    ...(menuRef.value?.querySelectorAll('[data-breadcrumb-entity]') || []),
];

const clearDebounce = () => {
    if (debounceTimer !== null) {
        clearTimeout(debounceTimer);
        debounceTimer = null;
    }
};

const abortRequest = () => {
    requestSequence += 1;
    activeRequest?.abort();
    activeRequest = null;
    isLoading.value = false;
};

const cleanupPendingWork = () => {
    clearDebounce();
    abortRequest();
};

const requestEntities = async () => {
    clearDebounce();
    abortRequest();

    const normalizedQuery = query.value.trim();
    if (normalizedQuery.length === 1) {
        return;
    }

    if (!sourceHref.value) {
        hasError.value = true;
        hasLoaded.value = false;
        return;
    }

    const controller = new AbortController();
    const sequence = ++requestSequence;
    activeRequest = controller;
    isLoading.value = true;
    hasError.value = false;

    try {
        const response = await axios.get(sourceHref.value, {
            params: {
                q: normalizedQuery || undefined,
                type: props.source?.type || undefined,
                current_id: props.source?.currentId ?? undefined,
                parent: props.source?.parent ?? undefined,
            },
            headers: {
                Accept: 'application/json',
            },
            signal: controller.signal,
        });

        if (sequence !== requestSequence) {
            return;
        }

        entities.value = normalizePayload(response.data);
        hasLoaded.value = true;
    } catch (error) {
        if (error?.code !== 'ERR_CANCELED' && sequence === requestSequence) {
            entities.value = [];
            hasError.value = true;
            hasLoaded.value = false;
        }
    } finally {
        if (sequence === requestSequence) {
            activeRequest = null;
            isLoading.value = false;
        }
    }
};

const focusInput = () => nextTick(() => inputRef.value?.focus());

const openPopover = () => {
    if (!isOpen.value) {
        openMenu();
    }

    focusInput();

    if (!hasLoaded.value && !isLoading.value) {
        requestEntities();
    }
};

const closePopover = ({ restoreFocus = false } = {}) => {
    closeMenu();
    cleanupPendingWork();

    if (restoreFocus) {
        nextTick(() => toggleRef.value?.focus());
    }
};

const togglePopover = () => {
    if (isOpen.value) {
        closePopover();
        return;
    }

    openPopover();
};

const handleTriggerKeydown = (event) => {
    if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) {
        event.preventDefault();
        openPopover();
        return;
    }

    if (event.key === 'Escape' && isOpen.value) {
        event.preventDefault();
        closePopover({ restoreFocus: true });
    }
};

const focusResult = (index) => {
    const elements = resultElements();
    if (!elements.length) {
        return;
    }

    const normalizedIndex = ((index % elements.length) + elements.length) % elements.length;
    elements[normalizedIndex]?.focus();
};

const handleDialogKeydown = (event) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        closePopover({ restoreFocus: true });
        return;
    }

    const elements = resultElements();
    const focusedIndex = elements.indexOf(document.activeElement);

    if (event.target === inputRef.value && event.key === 'ArrowDown') {
        event.preventDefault();
        focusResult(0);
        return;
    }
    if (event.target === inputRef.value && event.key === 'ArrowUp') {
        event.preventDefault();
        focusResult(elements.length - 1);
        return;
    }
    if (focusedIndex < 0) {
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        focusResult(focusedIndex + 1);
        return;
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        focusResult(focusedIndex - 1);
        return;
    }
    if (event.key === 'Home') {
        event.preventDefault();
        focusResult(0);
        return;
    }
    if (event.key === 'End') {
        event.preventDefault();
        focusResult(elements.length - 1);
    }
};

const selectEntity = (entity) => {
    closePopover();
    emit('select', entity);
};

watch(query, () => {
    if (!isOpen.value) {
        return;
    }

    clearDebounce();
    abortRequest();
    hasError.value = false;

    if (query.value.trim().length === 1) {
        return;
    }

    hasLoaded.value = false;
    isLoading.value = true;
    debounceTimer = setTimeout(requestEntities, 250);
});

watch(
    () => [isLoading.value, hasError.value, entities.value.length],
    () => {
        if (isOpen.value) {
            nextTick(updatePosition);
        }
    },
);

watch(isOpen, (open) => {
    if (!open) {
        cleanupPendingWork();
    }
});

onMounted(() => {
    stopNavigationListener = router.on('start', () => closePopover());
});

onBeforeUnmount(() => {
    stopNavigationListener?.();
    cleanupPendingWork();
});
</script>

<template>
    <span class="inline-flex shrink-0">
        <button
            ref="toggleRef"
            type="button"
            class="inline-flex size-8 touch-manipulation items-center justify-center rounded-full text-current transition hover:bg-black/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-600 dark:hover:bg-white/10"
            :aria-label="label"
            :aria-controls="dialogId"
            :aria-expanded="isOpen ? 'true' : 'false'"
            aria-haspopup="dialog"
            @click="togglePopover"
            @keydown="handleTriggerKeydown"
        >
            <svg
                v-if="triggerVariant === 'separator'"
                aria-hidden="true"
                class="size-3.5 opacity-60 transition motion-reduce:transition-none"
                :class="isOpen ? 'rotate-90' : ''"
                viewBox="0 0 20 20"
                fill="currentColor"
            >
                <path
                    fill-rule="evenodd"
                    d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 1 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z"
                    clip-rule="evenodd"
                />
            </svg>
            <svg
                v-else
                aria-hidden="true"
                class="size-3.5 opacity-60 transition motion-reduce:transition-none"
                :class="isOpen ? 'rotate-180' : ''"
                viewBox="0 0 20 20"
                fill="currentColor"
            >
                <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                :id="dialogId"
                ref="menuRef"
                class="fixed z-[100] flex max-h-[min(24rem,calc(100vh-1.5rem))] w-[min(18rem,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-md border border-stone-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-900"
                :style="menuStyle"
                role="dialog"
                :aria-label="label"
                @keydown="handleDialogKeydown"
            >
                <div class="flex shrink-0 items-center gap-2 border-b border-stone-200 px-3 py-2 dark:border-neutral-700">
                    <svg aria-hidden="true" class="size-4 shrink-0 text-stone-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                        <circle cx="8.5" cy="8.5" r="5.75" />
                        <path stroke-linecap="round" d="m12.75 12.75 4 4" />
                    </svg>
                    <label :for="searchId" class="sr-only">{{ label }}</label>
                    <input
                        :id="searchId"
                        ref="inputRef"
                        v-model="query"
                        type="search"
                        autocomplete="off"
                        class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-stone-800 outline-none placeholder:text-stone-400 focus:ring-0 dark:text-neutral-100 dark:placeholder:text-neutral-500"
                        :placeholder="t('workspace_hub.breadcrumbs.entity_search_placeholder')"
                    />
                </div>

                <div
                    class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain p-1"
                    :aria-busy="isLoading ? 'true' : 'false'"
                >
                    <div
                        v-if="isLoading"
                        role="status"
                        aria-live="polite"
                        aria-atomic="true"
                        class="px-3 py-5 text-center text-sm text-stone-500 dark:text-neutral-400"
                    >
                        {{ t('workspace_hub.breadcrumbs.entity_loading') }}
                    </div>

                    <div
                        v-else-if="hasError"
                        role="alert"
                        aria-live="assertive"
                        aria-atomic="true"
                        class="px-3 py-5 text-center text-sm text-red-700 dark:text-red-300"
                    >
                        {{ t('workspace_hub.breadcrumbs.entity_error') }}
                    </div>

                    <div
                        v-else-if="hasLoaded && entities.length === 0"
                        role="status"
                        aria-live="polite"
                        aria-atomic="true"
                        class="px-3 py-5 text-center text-sm text-stone-500 dark:text-neutral-400"
                    >
                        {{ t('workspace_hub.breadcrumbs.entity_empty') }}
                    </div>

                    <Link
                        v-for="entity in entities"
                        v-else
                        :key="entity.key"
                        :href="entity.href"
                        data-breadcrumb-entity
                        class="flex min-w-0 items-center gap-2 rounded-sm px-3 py-2 text-sm text-stone-700 hover:bg-stone-100 focus-visible:bg-stone-100 focus-visible:outline-none dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus-visible:bg-neutral-800"
                        @click="selectEntity(entity)"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ entity.label }}</span>
                            <span
                                v-if="entity.subtitle"
                                class="block truncate text-xs text-stone-500 dark:text-neutral-400"
                            >
                                {{ entity.subtitle }}
                            </span>
                        </span>
                        <span
                            v-if="entity.current"
                            class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-200"
                        >
                            <svg aria-hidden="true" class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.31a1 1 0 0 1-1.42.004L3.29 9.268a1 1 0 1 1 1.414-1.414l4.04 4.04 6.542-6.598a1 1 0 0 1 1.418-.006Z" clip-rule="evenodd" />
                            </svg>
                            <span class="sr-only">{{ t('workspace_hub.breadcrumbs.current') }}</span>
                        </span>
                    </Link>
                </div>
            </div>
        </Teleport>
    </span>
</template>
