<script setup>
import { useI18n } from 'vue-i18n';
import AsyncDropzonePlaceholder from '@/Components/AsyncDropzonePlaceholder.vue';

defineProps({ failed: Boolean });
defineEmits(['retry']);

const { t } = useI18n();
</script>

<template>
    <div v-if="failed" role="alert" class="space-y-3 py-4">
        <p class="text-sm text-red-600 dark:text-red-400">{{ t('quick_create.errors.load_form') }}</p>
        <button
            type="button"
            class="rounded-sm border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-800"
            @click="$emit('retry')"
        >
            {{ t('quick_create.retry') }}
        </button>
    </div>
    <AsyncDropzonePlaceholder v-else />
</template>
