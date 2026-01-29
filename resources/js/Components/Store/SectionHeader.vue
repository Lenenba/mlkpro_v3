<script setup>
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    actionLabel: { type: String, default: '' },
    actionAria: { type: String, default: '' },
});

const emit = defineEmits(['action']);
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-1">
            <h2 class="text-xl font-semibold text-slate-900">
                {{ title }}
            </h2>
            <p v-if="subtitle" class="text-sm text-slate-500">
                {{ subtitle }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <slot name="actions" />
            <button
                v-if="actionLabel"
                type="button"
                class="rounded-sm border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-slate-300 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                :aria-label="actionAria || actionLabel"
                @click="emit('action')"
            >
                {{ actionLabel }}
            </button>
        </div>
    </div>
</template>
