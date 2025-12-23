<script setup>
const props = defineProps({
    item: Object,
});
</script>
<template>
    <!-- List -->
    <dl class="grid grid-cols-2 gap-x-2">

        <dt class="py-1 text-sm text-gray-500 dark:text-neutral-500">
            Number:
        </dt>
        <dd
            class="py-1 inline-flex justify-end items-center gap-x-2 text-end font-medium text-sm text-gray-700 dark:text-neutral-200">
            {{ item.number || '-' }}
        </dd>
        <dt class="py-1 text-sm text-gray-500 dark:text-neutral-500">
            Name:
        </dt>
        <dd
            class="py-1 inline-flex justify-end items-center gap-x-2 text-end font-medium text-sm text-gray-700 dark:text-neutral-200">
            {{ `${item.first_name || ''} ${item.last_name || ''}`.trim() || '-' }}
        </dd>

        <dt class="py-1 text-sm text-gray-500 dark:text-neutral-500">
            Phone:
        </dt>
        <dd
            class="py-1 inline-flex justify-end items-center gap-x-2 text-end font-medium text-sm text-gray-700 dark:text-neutral-200">
            {{ item.phone || '-' }}
        </dd>

        <dt class="py-1 text-sm text-gray-500 dark:text-neutral-500">
            Email:
        </dt>
        <dd
            class="py-1 inline-flex justify-end items-center gap-x-2 text-end font-medium text-sm text-gray-700 dark:text-neutral-200">
            {{ item.email || '-' }}
        </dd>
    </dl>
    <!-- End List -->
</template>
