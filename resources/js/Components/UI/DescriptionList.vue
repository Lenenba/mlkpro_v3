<script setup>
import { useI18n } from 'vue-i18n';

const props = defineProps({
    item: Object,
});

const { t } = useI18n();
</script>
<template>
    <!-- List -->
    <dl class="grid grid-cols-2 gap-x-2">

        <dt class="py-1 text-sm text-stone-500 dark:text-neutral-500">
            {{ t('customers.details.contact.fields.number') }}:
        </dt>
        <dd
            class="py-1 inline-flex justify-end items-center gap-x-2 text-end font-medium text-sm text-stone-700 dark:text-neutral-200">
            {{ item.number || '-' }}
        </dd>
        <dt class="py-1 text-sm text-stone-500 dark:text-neutral-500">
            {{ t('customers.details.contact.fields.name') }}:
        </dt>
        <dd
            class="py-1 inline-flex justify-end items-center gap-x-2 text-end font-medium text-sm text-stone-700 dark:text-neutral-200">
            {{ `${item.first_name || ''} ${item.last_name || ''}`.trim() || '-' }}
        </dd>

        <dt class="py-1 text-sm text-stone-500 dark:text-neutral-500">
            {{ t('customers.details.contact.fields.phone') }}:
        </dt>
        <dd
            class="py-1 inline-flex justify-end items-center gap-x-2 text-end font-medium text-sm text-stone-700 dark:text-neutral-200">
            {{ item.phone || '-' }}
        </dd>

        <dt class="py-1 text-sm text-stone-500 dark:text-neutral-500">
            {{ t('customers.details.contact.fields.email') }}:
        </dt>
        <dd
            class="py-1 inline-flex justify-end items-center gap-x-2 text-end font-medium text-sm text-stone-700 dark:text-neutral-200">
            {{ item.email || '-' }}
        </dd>
    </dl>
    <!-- End List -->
</template>
