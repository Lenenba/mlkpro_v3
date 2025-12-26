<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: String,
    svg: String,
    label: String,
    compact: {
        type: Boolean,
        default: true,
    },
    active: {
        type: Boolean,
    },
});


const classes = computed(() =>
    props.active
        ? 'flex justify-center items-center gap-x-3 size-9 rounded-sm group-hover:bg-stone-200 group-focus:bg-stone-200 dark:group-hover:bg-neutral-800 dark:group-focus:bg-neutral-800 transition bg-stone-200 dark:bg-neutral-800'
        : 'flex justify-center items-center gap-x-3 size-9 rounded-sm group-hover:bg-stone-100 group-focus:bg-stone-100 dark:group-hover:bg-neutral-800 dark:group-focus:bg-neutral-800 transition',
);

const anchorRef = ref(null);
const anchorEl = ref(null);
const tooltipVisible = ref(false);
const tooltipStyle = ref({});
let listenersBound = false;

const getAnchorElement = () => {
    if (!anchorRef.value) {
        return null;
    }
    return anchorRef.value.$el ? anchorRef.value.$el : anchorRef.value;
};

const updateTooltipPosition = () => {
    const el = anchorEl.value || getAnchorElement();
    if (!el) {
        return;
    }
    const rect = el.getBoundingClientRect();
    tooltipStyle.value = {
        left: `${rect.right + 10}px`,
        top: `${rect.top + rect.height / 2}px`,
    };
};

const addListeners = () => {
    if (listenersBound) {
        return;
    }
    window.addEventListener('resize', updateTooltipPosition);
    window.addEventListener('scroll', updateTooltipPosition, true);
    listenersBound = true;
};

const removeListeners = () => {
    if (!listenersBound) {
        return;
    }
    window.removeEventListener('resize', updateTooltipPosition);
    window.removeEventListener('scroll', updateTooltipPosition, true);
    listenersBound = false;
};

const showTooltip = (event) => {
    if (!props.compact) {
        return;
    }
    anchorEl.value = event?.currentTarget || getAnchorElement();
    tooltipVisible.value = true;
    updateTooltipPosition();
    addListeners();
};

const hideTooltip = () => {
    tooltipVisible.value = false;
    anchorEl.value = null;
    removeListeners();
};

onBeforeUnmount(() => {
    removeListeners();
});
</script>
<template>
    <li>
        <Link
            ref="anchorRef"
            :href="route(href)"
            class="relative group flex flex-col justify-center items-center gap-y-1 text-[11px] text-stone-600 dark:text-neutral-200 disabled:opacity-50 disabled:pointer-events-none focus:outline-none"
            :aria-label="label"
            :title="label"
            @mouseenter="showTooltip"
            @mouseleave="hideTooltip"
            @focus="showTooltip"
            @blur="hideTooltip">
            <span
                :class="classes">
                <slot name="icon"/>
            </span>
            <span v-if="!compact">{{ label }}</span>
            <span v-else class="sr-only">{{ label }}</span>
        </Link>
        <Teleport to="body">
            <div
                v-if="compact && tooltipVisible"
                class="fixed z-[9999] -translate-y-1/2 whitespace-nowrap rounded-sm bg-stone-900 px-2 py-1 text-[11px] text-white shadow-lg pointer-events-none dark:bg-neutral-800"
                :style="tooltipStyle"
                role="tooltip"
            >
                {{ label }}
            </div>
        </Teleport>
    </li>
</template>
