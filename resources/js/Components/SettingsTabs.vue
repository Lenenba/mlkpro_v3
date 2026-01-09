<script setup>
const props = defineProps({
    modelValue: {
        type: String,
        required: true,
    },
    tabs: {
        type: Array,
        required: true,
    },
    ariaLabel: {
        type: String,
        default: 'Sections',
    },
    idPrefix: {
        type: String,
        default: 'settings',
    },
});

const emit = defineEmits(['update:modelValue']);

const selectTab = (id) => {
    if (id === props.modelValue) {
        return;
    }
    emit('update:modelValue', id);
};

const tabId = (id) => `${props.idPrefix}-tab-${id}`;
const panelId = (id) => `${props.idPrefix}-panel-${id}`;
</script>

<template>
    <div class="rounded-sm border border-stone-200 bg-stone-50 p-1 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/60">
        <div class="flex gap-2 overflow-x-auto" role="tablist" :aria-label="ariaLabel">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                role="tab"
                :id="tabId(tab.id)"
                :aria-selected="modelValue === tab.id"
                :aria-controls="panelId(tab.id)"
                class="flex min-w-[150px] flex-1 flex-col items-start gap-1 rounded-sm border px-3 py-2 text-left text-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1 focus-visible:ring-offset-stone-50 dark:focus-visible:ring-offset-neutral-900"
                :class="modelValue === tab.id
                    ? 'border-emerald-200 bg-white text-stone-900 shadow-sm dark:border-emerald-500/40 dark:bg-neutral-900 dark:text-neutral-100'
                    : 'border-transparent text-stone-600 hover:bg-white/70 hover:text-stone-800 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                @click="selectTab(tab.id)"
            >
                <span class="text-sm font-semibold">{{ tab.label }}</span>
                <span v-if="tab.description" class="text-[11px]" :class="modelValue === tab.id ? 'text-emerald-700/80 dark:text-emerald-200/70' : 'text-stone-500 dark:text-neutral-400'">
                    {{ tab.description }}
                </span>
            </button>
        </div>
    </div>
</template>
