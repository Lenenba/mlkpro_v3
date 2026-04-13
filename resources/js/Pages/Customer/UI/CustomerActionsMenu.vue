<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';

defineProps({
    customer: {
        type: Object,
        required: true,
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['toggle-archive', 'delete']);

const { t } = useI18n();
</script>

<template>
    <AdminDataTableActions :label="t('customers.actions.view')">
        <Link
            :href="route('customer.show', customer)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('customers.actions.view') }}
        </Link>
        <Link
            v-if="canEdit"
            :href="route('customer.edit', customer)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('customers.actions.edit') }}
        </Link>
        <button
            v-if="canEdit"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
            data-tone="warning"
            @click="$emit('toggle-archive')"
        >
            {{ customer.is_active ? t('customers.actions.archive') : t('customers.actions.restore') }}
        </button>
        <div v-if="canEdit" class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
        <button
            v-if="canEdit"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback"
            data-tone="danger"
            @click="$emit('delete')"
        >
            {{ t('customers.actions.delete') }}
        </button>
    </AdminDataTableActions>
</template>
