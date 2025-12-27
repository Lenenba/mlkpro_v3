<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
});

const categoryForm = useForm({
    name: '',
});

const canAddCategory = computed(() => categoryForm.name.trim().length > 0);

const addCategory = () => {
    if (!canAddCategory.value) {
        return;
    }

    categoryForm.post(route('settings.categories.store'), {
        preserveScroll: true,
        onSuccess: () => categoryForm.reset('name'),
    });
};
</script>

<template>
    <Head title="Categories" />

    <AuthenticatedLayout>
        <div class="w-full max-w-4xl space-y-5">
            <div>
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Categories</h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Gere les categories utilisees pour les services et produits.
                </p>
            </div>

            <div class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="p-4 space-y-4">
                    <div class="flex flex-wrap gap-2">
                        <span v-for="category in props.categories" :key="category.id"
                            class="rounded-full bg-stone-100 px-3 py-1 text-xs text-stone-700 dark:bg-neutral-900 dark:text-neutral-200">
                            {{ category.name }}
                        </span>
                        <span v-if="!props.categories.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            Aucune categorie pour le moment.
                        </span>
                    </div>

                    <div class="flex flex-col gap-3 md:flex-row md:items-end">
                        <div class="flex-1">
                            <FloatingInput v-model="categoryForm.name" label="Nouvelle categorie" />
                            <InputError class="mt-1" :message="categoryForm.errors.name" />
                        </div>
                        <button type="button" @click="addCategory" :disabled="!canAddCategory || categoryForm.processing"
                            class="w-full md:w-auto py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Ajouter
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
