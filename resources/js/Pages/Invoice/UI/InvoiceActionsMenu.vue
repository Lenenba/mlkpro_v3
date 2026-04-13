<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';

defineProps({
    invoice: {
        type: Object,
        required: true,
    },
    canSend: {
        type: Boolean,
        default: false,
    },
    sending: {
        type: Boolean,
        default: false,
    },
    sendLabel: {
        type: String,
        default: '',
    },
});

defineEmits(['send']);

const { t } = useI18n();
</script>

<template>
    <AdminDataTableActions :label="t('invoices.actions.view_invoice')">
        <button
            v-if="canSend"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 dark:text-neutral-300 dark:hover:bg-neutral-800"
            :disabled="sending"
            @click="$emit('send')"
        >
            {{ sendLabel }}
        </button>
        <Link
            :href="route('invoice.show', invoice.id)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('invoices.actions.view_invoice') }}
        </Link>
        <Link
            v-if="invoice.work?.id"
            :href="route('work.show', invoice.work.id)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('invoices.actions.view_job') }}
        </Link>
        <Link
            v-if="invoice.customer?.id"
            :href="route('customer.show', invoice.customer.id)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('invoices.actions.view_customer') }}
        </Link>
    </AdminDataTableActions>
</template>
