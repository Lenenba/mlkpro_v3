<script setup>
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';

defineProps({
    activeTab: {
        type: String,
        required: true,
    },
    tabPrefix: {
        type: String,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    categoryForm: {
        type: Object,
        required: true,
    },
    canAddCategory: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['submit']);
</script>

<template>
    <div
        v-show="activeTab === 'categories'"
        :id="`${tabPrefix}-panel-categories`"
        role="tabpanel"
        :aria-labelledby="`${tabPrefix}-tab-categories`"
        class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
    >
        <div class="p-4 space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('settings.company.categories.title') }}
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('settings.company.categories.description') }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <span
                    v-for="category in categories"
                    :key="category.id"
                    class="rounded-full bg-stone-100 px-3 py-1 text-xs text-stone-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                    {{ category.name }}
                </span>
                <span v-if="!categories.length" class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('settings.company.categories.empty') }}
                </span>
            </div>

            <div class="flex flex-col gap-3 md:flex-row md:items-end">
                <div class="flex-1">
                    <FloatingInput v-model="categoryForm.name" :label="$t('settings.company.categories.new_label')" />
                    <InputError class="mt-1" :message="categoryForm.errors.name" />
                </div>
                <button
                    type="button"
                    :disabled="!canAddCategory || categoryForm.processing"
                    class="w-full md:w-auto py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none"
                    @click="$emit('submit')"
                >
                    {{ $t('settings.company.categories.add') }}
                </button>
            </div>
        </div>
    </div>
</template>
