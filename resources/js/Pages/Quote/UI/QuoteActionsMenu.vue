<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';

const props = defineProps({
    quote: {
        type: Object,
        required: true,
    },
    archived: {
        type: Boolean,
        default: false,
    },
});

defineEmits([
    'send-email',
    'accept',
    'convert',
    'archive',
    'restore',
]);

const { t } = useI18n();
</script>

<template>
    <AdminDataTableActions
        :label="t('quotes.actions.view')"
        :trigger-test-id="`quote-actions-trigger-${quote.id}`"
    >
        <Link
            :href="route('customer.quote.show', quote)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('quotes.actions.view') }}
        </Link>
        <Link
            v-if="!archived && quote.status !== 'accepted'"
            :href="route('customer.quote.edit', quote)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('quotes.actions.edit') }}
        </Link>
        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
        <button
            v-if="!archived"
            type="button"
            data-testid="demo-quote-send"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-neutral-800 action-feedback"
            data-tone="info"
            @click="$emit('send-email')"
        >
            {{ t('quotes.actions.send_email') }}
        </button>
        <button
            v-if="!archived && quote.status !== 'accepted' && quote.status !== 'declined'"
            type="button"
            :data-testid="`quote-accept-${quote.id}`"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback"
            @click="$emit('accept')"
        >
            {{ t('quotes.actions.accept_quote') }}
        </button>
        <button
            v-if="!archived"
            type="button"
            data-testid="demo-quote-convert"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback"
            @click="$emit('convert')"
        >
            {{ t('quotes.actions.create_job') }}
        </button>
        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
        <button
            v-if="!archived"
            type="button"
            :data-testid="`quote-archive-${quote.id}`"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback"
            data-tone="danger"
            @click="$emit('archive')"
        >
            {{ t('quotes.actions.archive') }}
        </button>
        <button
            v-else
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback"
            @click="$emit('restore')"
        >
            {{ t('quotes.actions.restore') }}
        </button>
    </AdminDataTableActions>
</template>
