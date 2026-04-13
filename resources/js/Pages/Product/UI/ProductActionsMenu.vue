<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';

defineProps({
    product: {
        type: Object,
        required: true,
    },
});

defineEmits(['quick-edit', 'adjust', 'duplicate', 'toggle-archive', 'delete']);

const { t } = useI18n();
</script>

<template>
    <AdminDataTableActions :label="t('products.aria.dropdown')" menu-width-class="w-48">
        <Link
            :href="route('product.show', product.id)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('products.actions.view') }}
        </Link>
        <button
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
            @click="$emit('quick-edit')"
        >
            {{ t('products.actions.quick_edit') }}
        </button>
        <button
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
            @click="$emit('adjust')"
        >
            {{ t('products.actions.adjust_stock') }}
        </button>
        <button
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
            @click="$emit('duplicate')"
        >
            {{ t('products.actions.duplicate') }}
        </button>
        <button
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
            data-tone="warning"
            @click="$emit('toggle-archive')"
        >
            {{ product.is_active ? t('products.actions.archive') : t('products.actions.restore') }}
        </button>
        <button
            type="button"
            :data-hs-overlay="'#hs-pro-edit' + product.id"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
        >
            {{ t('products.actions.edit') }}
        </button>
        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
        <button
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback"
            data-tone="danger"
            @click="$emit('delete')"
        >
            {{ t('products.actions.delete') }}
        </button>
    </AdminDataTableActions>
</template>
