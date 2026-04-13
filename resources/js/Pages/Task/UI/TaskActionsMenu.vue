<script setup>
import { useI18n } from 'vue-i18n';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';

defineProps({
    task: {
        type: Object,
        required: true,
    },
    canChangeStatus: {
        type: Boolean,
        default: false,
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    canDelete: {
        type: Boolean,
        default: false,
    },
    locked: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['set-status', 'edit', 'add-proof', 'delete']);

const { t } = useI18n();
</script>

<template>
    <div class="inline-flex" @click.stop>
        <AdminDataTableActions :label="t('tasks.table.actions')" menu-width-class="w-44">
            <div class="px-2 py-1 text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                {{ $t('tasks.actions.set_status') }}
            </div>
            <button
                type="button"
                :disabled="!canChangeStatus || task.status === 'todo' || locked"
                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
                @click="$emit('set-status', 'todo')"
            >
                {{ $t('tasks.status.todo') }}
            </button>
            <button
                type="button"
                :disabled="!canChangeStatus || task.status === 'in_progress' || locked"
                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
                @click="$emit('set-status', 'in_progress')"
            >
                {{ $t('tasks.status.in_progress') }}
            </button>
            <button
                type="button"
                data-testid="demo-task-mark-done"
                :disabled="!canChangeStatus || task.status === 'done' || locked"
                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
                @click="$emit('set-status', 'done')"
            >
                {{ $t('tasks.status.done') }}
            </button>

            <template v-if="canManage || canDelete">
                <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
            </template>

            <button
                v-if="canManage"
                type="button"
                :disabled="locked"
                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
                @click="$emit('edit')"
            >
                {{ $t('tasks.actions.edit') }}
            </button>
            <button
                v-if="canChangeStatus"
                type="button"
                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
                @click="$emit('add-proof')"
            >
                {{ $t('tasks.actions.add_proof') }}
            </button>
            <button
                v-if="canDelete"
                type="button"
                data-tone="danger"
                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback"
                @click="$emit('delete')"
            >
                {{ $t('tasks.actions.delete') }}
            </button>
        </AdminDataTableActions>
    </div>
</template>
