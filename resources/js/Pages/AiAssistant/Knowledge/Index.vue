<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, Loader2, Plus, Trash2 } from 'lucide-vue-next';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';

const props = defineProps({
    items: {
        type: Object,
        default: () => ({ data: [] }),
    },
});

const rows = computed(() => props.items?.data || []);
const processing = ref(false);
const errorMessage = ref('');
const form = reactive({
    title: '',
    category: '',
    content: '',
    is_active: true,
});

const resetForm = () => {
    form.title = '';
    form.category = '';
    form.content = '';
    form.is_active = true;
};

const reload = () => {
    router.reload({
        only: ['items'],
        preserveScroll: true,
    });
};

const storeItem = async () => {
    processing.value = true;
    errorMessage.value = '';

    try {
        await axios.post(route('admin.ai-assistant.knowledge.store'), {
            title: form.title,
            category: form.category || null,
            content: form.content,
            is_active: form.is_active,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });
        resetForm();
        reload();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Impossible de sauvegarder cet element.';
    } finally {
        processing.value = false;
    }
};

const toggleItem = async (item) => {
    errorMessage.value = '';

    try {
        await axios.put(route('admin.ai-assistant.knowledge.update', item.id), {
            title: item.title,
            category: item.category || null,
            content: item.content,
            is_active: !item.is_active,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });
        reload();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Impossible de modifier cet element.';
    }
};

const deleteItem = async (item) => {
    errorMessage.value = '';

    try {
        await axios.delete(route('admin.ai-assistant.knowledge.destroy', item.id), {
            headers: {
                Accept: 'application/json',
            },
        });
        reload();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Impossible de supprimer cet element.';
    }
};
</script>

<template>
    <Head title="Connaissances IA" />

    <AuthenticatedLayout>
        <div class="mx-auto grid w-[1200px] max-w-full gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-4">
                <header class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <Link
                        :href="route('admin.ai-assistant.settings.edit')"
                        class="text-sm font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-300"
                    >
                        Reglages
                    </Link>
                    <h1 class="mt-2 text-xl font-semibold text-stone-900 dark:text-neutral-50">
                        Connaissances IA
                    </h1>
                </header>

                <section class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                            <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3 text-left">Titre</th>
                                    <th class="px-4 py-3 text-left">Categorie</th>
                                    <th class="px-4 py-3 text-left">Statut</th>
                                    <th class="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr v-for="item in rows" :key="item.id" class="text-stone-700 dark:text-neutral-200">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold">{{ item.title }}</div>
                                        <div class="mt-1 line-clamp-2 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ item.content }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">{{ item.category || '-' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="item.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-stone-600'">
                                            {{ item.is_active ? 'Actif' : 'Inactif' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex justify-end gap-2">
                                            <button
                                                type="button"
                                                class="inline-flex size-8 items-center justify-center rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                                :aria-label="item.is_active ? 'Desactiver' : 'Activer'"
                                                @click="toggleItem(item)"
                                            >
                                                <CheckCircle2 class="size-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex size-8 items-center justify-center rounded-sm border border-stone-200 text-rose-600 hover:bg-rose-50 dark:border-neutral-700 dark:text-rose-300 dark:hover:bg-neutral-800"
                                                aria-label="Supprimer"
                                                @click="deleteItem(item)"
                                            >
                                                <Trash2 class="size-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!rows.length">
                                    <td colspan="4" class="px-4 py-8 text-center text-sm text-stone-500 dark:text-neutral-400">
                                        Aucune connaissance.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            <aside class="h-fit rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Nouvel element</h2>
                <form class="mt-3 space-y-3" @submit.prevent="storeItem">
                    <FloatingInput v-model="form.title" label="Titre" required />
                    <FloatingInput v-model="form.category" label="Categorie" />
                    <FloatingTextarea v-model="form.content" label="Contenu" required />
                    <label class="flex items-center gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-600">
                        <span>Actif</span>
                    </label>
                    <p v-if="errorMessage" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {{ errorMessage }}
                    </p>
                    <button
                        type="submit"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="processing"
                    >
                        <Loader2 v-if="processing" class="size-4 animate-spin" />
                        <Plus v-else class="size-4" />
                        Ajouter
                    </button>
                </form>
            </aside>
        </div>
    </AuthenticatedLayout>
</template>
