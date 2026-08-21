<script setup>
defineProps({
    cards: {
        type: Array,
        default: () => [],
    },
    labelledBy: {
        type: String,
        default: undefined,
    },
});

defineEmits(['activate']);

const toneClasses = {
    indigo: 'border-t-indigo-600',
    emerald: 'border-t-emerald-600',
    sky: 'border-t-sky-600',
    amber: 'border-t-amber-600',
    rose: 'border-t-rose-600',
    violet: 'border-t-violet-600',
    stone: 'border-t-stone-500',
    cyan: 'border-t-cyan-600',
};

const cardClass = (card) => [
    toneClasses[card.tone] || toneClasses.stone,
    card.interactive
        ? 'cursor-pointer hover:-translate-y-0.5 hover:border-stone-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 dark:hover:border-neutral-600 dark:focus-visible:ring-offset-neutral-900'
        : '',
    card.active
        ? 'ring-2 ring-green-600 ring-offset-2 dark:ring-green-400 dark:ring-offset-neutral-900'
        : '',
];
</script>

<template>
    <div
        class="grid grid-cols-[repeat(auto-fill,minmax(7rem,1fr))] gap-2 md:gap-3"
        role="group"
        :aria-labelledby="labelledBy"
    >
        <component
            :is="card.interactive ? 'button' : 'div'"
            v-for="card in cards"
            :key="card.key"
            :type="card.interactive ? 'button' : undefined"
            class="group relative flex aspect-square min-h-28 w-full flex-col justify-between overflow-hidden rounded-sm border border-t-2 border-stone-200 bg-white p-3 text-start shadow-sm transition motion-reduce:transform-none motion-reduce:transition-none dark:border-neutral-700 dark:bg-neutral-800"
            :class="cardClass(card)"
            :aria-pressed="card.interactive ? String(Boolean(card.active)) : undefined"
            :aria-label="card.ariaLabel"
            @click="card.interactive && $emit('activate', card.action)"
        >
            <div class="flex items-start justify-between gap-2">
                <span class="line-clamp-2 text-xs font-medium leading-tight text-stone-500 dark:text-neutral-400">
                    {{ card.label }}
                </span>
                <svg
                    class="size-4 shrink-0 text-stone-400 transition group-hover:text-stone-600 dark:text-neutral-500 dark:group-hover:text-neutral-300"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <template v-if="card.icon === 'user-plus'">
                        <path d="M15 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                        <circle cx="8.5" cy="7" r="4" />
                        <path d="M19 8v6M22 11h-6" />
                    </template>
                    <template v-else-if="card.icon === 'calendar'">
                        <rect x="3" y="5" width="18" height="16" rx="2" />
                        <path d="M16 3v4M8 3v4M3 11h18" />
                    </template>
                    <template v-else-if="card.icon === 'star'">
                        <path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8-6.2-3.2L5.8 21 7 14.2l-5-4.9 6.9-1z" />
                    </template>
                    <template v-else-if="card.icon === 'invoice'">
                        <path d="M6 2h9l3 3v17H6z" />
                        <path d="M14 2v5h4M9 13h6M9 17h4" />
                    </template>
                    <template v-else-if="card.icon === 'repeat'">
                        <path d="m17 1 4 4-4 4" />
                        <path d="M3 11V9a4 4 0 0 1 4-4h14" />
                        <path d="m7 23-4-4 4-4" />
                        <path d="M21 13v2a4 4 0 0 1-4 4H3" />
                    </template>
                    <template v-else-if="card.icon === 'alert'">
                        <path d="M10.3 2.9 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0Z" />
                        <path d="M12 9v4M12 17h.01" />
                    </template>
                    <template v-else>
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                    </template>
                </svg>
            </div>

            <div>
                <div class="truncate text-lg font-semibold text-stone-800 md:text-xl dark:text-neutral-100">
                    {{ card.value }}
                </div>
                <div v-if="card.detail" class="mt-0.5 line-clamp-2 text-[11px] leading-tight text-stone-500 dark:text-neutral-400">
                    {{ card.detail }}
                </div>
            </div>

            <span
                v-if="card.active"
                class="absolute end-2 top-2 size-2 rounded-full bg-green-600 dark:bg-green-400"
                aria-hidden="true"
            />
        </component>
    </div>
</template>
