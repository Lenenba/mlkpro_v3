<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: String,
    svg: String,
    label: String,
    active: {
        type: Boolean,
    },
});


const classes = computed(() =>
    props.active
        ? 'flex justify-center items-center gap-x-3 size-9 rounded-lg group-hover:bg-gray-400 group-focus:bg-gray-300 dark:group-hover:bg-neutral-800 dark:group-focus:bg-neutral-800 transition bg-gray-300 dark:bg-neutral-800'
        : 'flex justify-center items-center gap-x-3 size-9 rounded-lg group-hover:bg-gray-300 group-focus:bg-gray-300 dark:group-hover:bg-neutral-800 dark:group-focus:bg-neutral-800 transition',
);
</script>
<template>
    <li>
        <Link :href="route(href)">
            <a
                class="relative group flex flex-col justify-center items-center gap-y-1 text-[11px] text-gray-600 dark:text-neutral-200 disabled:opacity-50 disabled:pointer-events-none focus:outline-none">
                <span
                    :class="classes">
                    <slot name="icon"/>
                </span>
                {{ label }}
            </a>
        </Link>
    </li>
</template>
