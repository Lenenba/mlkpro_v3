<script setup>
defineProps({
    eyebrow: { type: String, default: '' },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <section
        class="rounded-lg border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
        :class="compact ? 'p-4' : 'p-5'"
    >
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div class="min-w-0">
                <div
                    v-if="eyebrow"
                    class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400"
                >
                    {{ eyebrow }}
                </div>
                <h3 class="mt-1 text-base font-semibold text-stone-800 dark:text-neutral-100">
                    {{ title }}
                </h3>
                <p
                    v-if="description"
                    class="mt-1 max-w-3xl text-sm leading-6 text-stone-500 dark:text-neutral-400"
                >
                    {{ description }}
                </p>
            </div>
            <div v-if="$slots.actions" class="shrink-0">
                <slot name="actions" />
            </div>
        </div>

        <div :class="compact ? 'mt-4' : 'mt-5'">
            <slot />
        </div>
    </section>
</template>
