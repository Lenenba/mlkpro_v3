<script setup>
const props = defineProps({
    categories: { type: Array, default: () => [] },
    selected: { type: [String, Number], default: '' },
    allLabel: { type: String, default: 'All' },
    includeAll: { type: Boolean, default: true },
    size: { type: String, default: 'md' },
});

const emit = defineEmits(['select']);

const chipBase = {
    sm: 'px-2.5 py-1 text-xs',
    md: 'px-3 py-1.5 text-xs',
};

const chipClass = (active) => [
    'inline-flex items-center rounded-sm border font-semibold transition',
    chipBase[props.size] || chipBase.md,
    active
        ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-800',
].join(' ');
</script>

<template>
    <div class="flex items-center gap-2 overflow-x-auto pb-1">
        <button
            v-if="includeAll"
            type="button"
            :class="chipClass(!selected)"
            :aria-pressed="!selected"
            @click="emit('select', '')"
        >
            {{ allLabel }}
        </button>
        <button
            v-for="category in categories"
            :key="category.id"
            type="button"
            :class="chipClass(String(selected) === String(category.id))"
            :aria-pressed="String(selected) === String(category.id)"
            @click="emit('select', String(category.id))"
        >
            {{ category.name }}
        </button>
    </div>
</template>
