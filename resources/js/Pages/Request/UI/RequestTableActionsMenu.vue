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
    archived: {
        type: Boolean,
        default: false,
    },
    anonymized: {
        type: Boolean,
        default: false,
    },
    processing: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update', 'followUp', 'addNote', 'convert', 'archive', 'restore', 'anonymize', 'delete']);

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
            v-if="!archived"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
            @click="$emit('update')"
        >
            {{ t('requests.actions.change_status') }}
        </button>
        <button
            v-if="!archived"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
            @click="$emit('followUp')"
        >
            {{ t('requests.actions.plan_follow_up') }}
        </button>
        <button
            v-if="!archived"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
            @click="$emit('addNote')"
        >
            {{ t('requests.actions.add_note') }}
        </button>
        <button
            v-if="!archived && canUseQuotes && canConvert"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800"
            @click="$emit('convert')"
        >
            {{ t('requests.actions.convert') }}
        </button>
        <Link
            :href="route('prospects.show', lead.id)"
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
            v-if="!archived"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-amber-700 hover:bg-amber-50 disabled:opacity-50 dark:text-amber-300 dark:hover:bg-neutral-800"
            :disabled="processing"
            @click="$emit('archive')"
        >
            {{ t('requests.actions.archive') }}
        </button>
        <button
            v-else-if="!anonymized"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-600 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-neutral-800"
            :disabled="processing"
            @click="$emit('restore')"
        >
            {{ t('requests.actions.restore') }}
        </button>
        <button
            v-if="archived && !anonymized"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 dark:text-neutral-300 dark:hover:bg-neutral-800"
            :disabled="processing"
            @click="$emit('anonymize')"
        >
            {{ t('requests.actions.anonymize') }}
        </button>
        <button
            v-if="archived && anonymized"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 disabled:opacity-50 dark:text-red-400 dark:hover:bg-neutral-800"
            :disabled="processing"
            @click="$emit('delete')"
        >
            {{ t('requests.actions.delete') }}
        </button>
    </AdminDataTableActions>
</template>
