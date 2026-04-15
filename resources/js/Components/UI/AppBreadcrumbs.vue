<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});

const normalizedItems = computed(() => {
    const filteredItems = (props.items || []).filter((item) => Boolean(item?.label));

    return filteredItems.map((item, index) => ({
        ...item,
        key: item.key || `${index}-${item.label}`,
        isCurrent: index === filteredItems.length - 1 || Boolean(item.current),
    }));
});
</script>

<template>
    <nav
        v-if="normalizedItems.length > 1"
        aria-label="Breadcrumb"
        class="rounded-sm border border-stone-200/80 bg-white/90 px-3 py-2 shadow-sm backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/90"
    >
        <ol class="flex flex-wrap items-center gap-2">
            <li
                v-for="(item, index) in normalizedItems"
                :key="item.key"
                class="flex min-w-0 items-center gap-2"
            >
                <component
                    :is="item.href && !item.isCurrent ? Link : 'span'"
                    :href="item.href && !item.isCurrent ? item.href : undefined"
                    class="group inline-flex min-w-0 items-center gap-2 rounded-full px-2.5 py-1.5 text-sm transition"
                    :class="item.isCurrent
                        ? 'bg-stone-900 text-white shadow-sm dark:bg-neutral-100 dark:text-neutral-900'
                        : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'"
                    :aria-current="item.isCurrent ? 'page' : undefined"
                >
                    <svg
                        v-if="item.icon === 'home'"
                        aria-hidden="true"
                        class="h-4 w-4 shrink-0 text-sky-600 transition group-hover:text-sky-700 dark:text-sky-400 dark:group-hover:text-sky-300"
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <rect width="5.5" height="7.5" x="2.75" y="2.75" rx="1" />
                        <rect width="5.5" height="4.5" x="11.75" y="2.75" rx="1" />
                        <rect width="5.5" height="7.5" x="11.75" y="9.75" rx="1" />
                        <rect width="5.5" height="4.5" x="2.75" y="12.75" rx="1" />
                    </svg>
                    <span class="truncate">
                        {{ item.label }}
                    </span>
                </component>

                <svg
                    v-if="index < normalizedItems.length - 1"
                    aria-hidden="true"
                    class="h-3.5 w-3.5 shrink-0 text-stone-300 dark:text-neutral-600"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                >
                    <path
                        fill-rule="evenodd"
                        d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 1 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z"
                        clip-rule="evenodd"
                    />
                </svg>
            </li>
        </ol>
    </nav>
</template>
