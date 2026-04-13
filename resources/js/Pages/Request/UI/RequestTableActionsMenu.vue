<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';

defineProps({
    lead: {
        type: Object,
        required: true,
    },
    canUseQuotes: {
        type: Boolean,
        default: false,
    },
    canConvert: {
        type: Boolean,
        default: false,
    },
    processing: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update', 'convert', 'delete']);

const { t } = useI18n();
</script>

<template>
    <AdminDataTableActions :label="t('requests.table.actions')">
        <Link
            v-if="lead.quote"
            :href="route('customer.quote.show', lead.quote.id)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('requests.actions.view_quote') }}
        </Link>
        <button
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
            @click="$emit('update')"
        >
            {{ t('requests.actions.update') }}
        </button>
        <button
            v-if="canUseQuotes && canConvert"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800"
            @click="$emit('convert')"
        >
            {{ t('requests.actions.convert') }}
        </button>
        <Link
            :href="route('request.show', lead.id)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('requests.actions.view') }}
        </Link>
        <Link
            :href="route('pipeline.timeline', { entityType: 'request', entityId: lead.id })"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('requests.actions.timeline') }}
        </Link>
        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
        <button
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 disabled:opacity-50 dark:text-red-400 dark:hover:bg-neutral-800"
            :disabled="processing"
            @click="$emit('delete')"
        >
            {{ t('requests.actions.delete') }}
        </button>
    </AdminDataTableActions>
</template>
