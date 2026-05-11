<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    offers: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    catalogItems: {
        type: Array,
        default: () => [],
    },
    options: {
        type: Object,
        default: () => ({}),
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
});

const editingOfferId = ref(null);
const filterForm = ref({
    search: props.filters.search || '',
    type: props.filters.type || '',
    status: props.filters.status || '',
    is_public: props.filters.is_public ?? '',
});

const defaultItem = () => ({
    product_id: props.catalogItems[0]?.id || '',
    quantity: 1,
    unit_price: props.catalogItems[0]?.price || 0,
    is_optional: false,
});

const form = useForm({
    name: '',
    type: 'pack',
    status: 'draft',
    description: '',
    image_path: '',
    price: 0,
    currency_code: props.tenantCurrencyCode,
    validity_days: '',
    included_quantity: 1,
    unit_type: 'session',
    is_public: false,
    items: [defaultItem()],
});

const isEditing = computed(() => editingOfferId.value !== null);
const pageOffers = computed(() => props.offers?.data || []);

const money = (value, currency = props.tenantCurrencyCode) => {
    const amount = Number(value || 0);

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency || props.tenantCurrencyCode,
    }).format(amount);
};

const catalogItem = (id) => props.catalogItems.find((item) => Number(item.id) === Number(id));

const resetForm = () => {
    editingOfferId.value = null;
    form.reset();
    form.clearErrors();
    form.currency_code = props.tenantCurrencyCode;
    form.items = [defaultItem()];
};

const startEdit = (offer) => {
    editingOfferId.value = offer.id;
    form.clearErrors();
    form.name = offer.name || '';
    form.type = offer.type || 'pack';
    form.status = offer.status || 'draft';
    form.description = offer.description || '';
    form.image_path = offer.image_path || '';
    form.price = Number(offer.price || 0);
    form.currency_code = offer.currency_code || props.tenantCurrencyCode;
    form.validity_days = offer.validity_days || '';
    form.included_quantity = offer.included_quantity || 1;
    form.unit_type = offer.unit_type || 'session';
    form.is_public = Boolean(offer.is_public);
    form.items = (offer.items || []).length
        ? offer.items.map((item) => ({
            product_id: item.product_id,
            quantity: Number(item.quantity || 1),
            unit_price: Number(item.unit_price || 0),
            sort_order: item.sort_order || 0,
            is_optional: false,
        }))
        : [defaultItem()];
};

const addItem = () => {
    form.items.push({
        ...defaultItem(),
        sort_order: form.items.length,
    });
};

const removeItem = (index) => {
    if (form.items.length === 1) {
        return;
    }

    form.items.splice(index, 1);
};

const updateItemPrice = (item) => {
    const selected = catalogItem(item.product_id);
    if (selected) {
        item.unit_price = Number(selected.price || 0);
    }
};

const submit = () => {
    const payload = {
        preserveScroll: true,
        onSuccess: () => resetForm(),
    };

    if (isEditing.value) {
        form.put(route('offer-packages.update', editingOfferId.value), payload);

        return;
    }

    form.post(route('offer-packages.store'), payload);
};

const applyFilters = () => {
    router.get(route('offer-packages.index'), filterForm.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const duplicateOffer = (offer) => {
    router.post(route('offer-packages.duplicate', offer.id), {}, {
        preserveScroll: true,
    });
};

const archiveOffer = (offer) => {
    router.delete(route('offer-packages.destroy', offer.id), {
        preserveScroll: true,
    });
};

const restoreOffer = (offer) => {
    router.post(route('offer-packages.restore', offer.id), {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Packs et forfaits" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300">
                        Catalogue commercial
                    </p>
                    <h1 class="mt-1 text-2xl font-semibold text-stone-950 dark:text-neutral-50">
                        Packs et forfaits
                    </h1>
                </div>

                <button
                    type="button"
                    class="rounded-sm bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!catalogItems.length"
                    @click="resetForm"
                >
                    Nouvelle offre
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div
                    v-for="(value, key) in stats"
                    :key="key"
                    class="border border-stone-200 bg-white px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div class="text-xs uppercase tracking-[0.16em] text-stone-500 dark:text-neutral-400">
                        {{ key }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-stone-950 dark:text-neutral-50">
                        {{ value }}
                    </div>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_420px]">
                <section class="space-y-4">
                    <div class="border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="grid gap-3 md:grid-cols-5">
                            <input
                                v-model="filterForm.search"
                                type="search"
                                class="md:col-span-2 rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100"
                                placeholder="Rechercher"
                                @keyup.enter="applyFilters"
                            >
                            <select
                                v-model="filterForm.type"
                                class="rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100"
                            >
                                <option value="">Tous les types</option>
                                <option value="pack">Packs</option>
                                <option value="forfait">Forfaits</option>
                            </select>
                            <select
                                v-model="filterForm.status"
                                class="rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100"
                            >
                                <option value="">Tous les statuts</option>
                                <option value="draft">Brouillon</option>
                                <option value="active">Actif</option>
                                <option value="archived">Archive</option>
                            </select>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-800 transition hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-100 dark:hover:bg-neutral-800"
                                @click="applyFilters"
                            >
                                Filtrer
                            </button>
                        </div>
                    </div>

                    <div class="overflow-hidden border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                        <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-800">
                            <thead class="bg-stone-50 text-left text-xs uppercase tracking-[0.14em] text-stone-500 dark:bg-neutral-950 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3">Offre</th>
                                    <th class="px-4 py-3">Type</th>
                                    <th class="px-4 py-3">Prix</th>
                                    <th class="px-4 py-3">Elements</th>
                                    <th class="px-4 py-3">Statut</th>
                                    <th class="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="offer in pageOffers" :key="offer.id">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-stone-950 dark:text-neutral-50">
                                            {{ offer.name }}
                                        </div>
                                        <div class="mt-1 max-w-sm truncate text-xs text-stone-500 dark:text-neutral-400">
                                            {{ offer.description || 'Aucune description' }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        {{ offer.type }}
                                    </td>
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        {{ money(offer.price, offer.currency_code) }}
                                    </td>
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        {{ offer.items_count ?? offer.items?.length ?? 0 }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-sm border border-stone-200 px-2.5 py-1 text-xs font-medium text-stone-700 dark:border-neutral-700 dark:text-neutral-200">
                                            {{ offer.status }}
                                        </span>
                                        <span v-if="offer.is_public" class="ml-2 rounded-sm bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                            public
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <button type="button" class="text-sm font-medium text-emerald-700 hover:text-emerald-900 dark:text-emerald-300" @click="startEdit(offer)">
                                                Modifier
                                            </button>
                                            <button type="button" class="text-sm font-medium text-stone-600 hover:text-stone-900 dark:text-neutral-300" @click="duplicateOffer(offer)">
                                                Dupliquer
                                            </button>
                                            <button
                                                v-if="offer.status === 'archived'"
                                                type="button"
                                                class="text-sm font-medium text-blue-700 hover:text-blue-900 dark:text-blue-300"
                                                @click="restoreOffer(offer)"
                                            >
                                                Reactiver
                                            </button>
                                            <button
                                                v-else
                                                type="button"
                                                class="text-sm font-medium text-rose-700 hover:text-rose-900 dark:text-rose-300"
                                                @click="archiveOffer(offer)"
                                            >
                                                Archiver
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!pageOffers.length">
                                    <td colspan="6" class="px-4 py-10 text-center text-sm text-stone-500 dark:text-neutral-400">
                                        Aucune offre trouvee.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="offers.links?.length" class="flex flex-wrap gap-2">
                        <Link
                            v-for="link in offers.links"
                            :key="`${link.label}-${link.url}`"
                            :href="link.url || '#'"
                            class="rounded-sm border px-3 py-1.5 text-sm"
                            :class="[
                                link.active ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-stone-200 text-stone-700 dark:border-neutral-700 dark:text-neutral-200',
                                !link.url ? 'pointer-events-none opacity-50' : '',
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </section>

                <aside class="border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-stone-950 dark:text-neutral-50">
                                {{ isEditing ? 'Modifier l offre' : 'Nouvelle offre' }}
                            </h2>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                V1: prix fixe, pas d options facultatives, pas de packs imbriques.
                            </p>
                        </div>
                        <button v-if="isEditing" type="button" class="text-sm font-medium text-stone-500 hover:text-stone-900 dark:text-neutral-400 dark:hover:text-neutral-100" @click="resetForm">
                            Annuler
                        </button>
                    </div>

                    <div v-if="!catalogItems.length" class="mt-5 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                        Ajoutez au moins un produit ou service avant de creer un pack ou forfait.
                    </div>

                    <form v-else class="mt-5 space-y-4" @submit.prevent="submit">
                        <div>
                            <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Nom</label>
                            <input v-model="form.name" type="text" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                            <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Type</label>
                                <select v-model="form.type" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                                    <option value="pack">Pack</option>
                                    <option value="forfait">Forfait</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Statut</label>
                                <select v-model="form.status" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                                    <option value="draft">Brouillon</option>
                                    <option value="active">Actif</option>
                                    <option value="archived">Archive</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Description</label>
                            <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Prix fixe</label>
                                <input v-model="form.price" type="number" min="0" step="0.01" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                            </div>
                            <div>
                                <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Devise</label>
                                <select v-model="form.currency_code" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                                    <option v-for="currency in options.currencies || ['CAD']" :key="currency" :value="currency">
                                        {{ currency }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div v-if="form.type === 'forfait'" class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Quantite</label>
                                <input v-model="form.included_quantity" type="number" min="1" step="1" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                            </div>
                            <div>
                                <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Unite</label>
                                <select v-model="form.unit_type" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                                    <option value="session">Seance</option>
                                    <option value="hour">Heure</option>
                                    <option value="visit">Visite</option>
                                    <option value="credit">Credit</option>
                                    <option value="month">Mois</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-stone-700 dark:text-neutral-200">Validite</label>
                                <input v-model="form.validity_days" type="number" min="1" step="1" placeholder="jours" class="mt-1 w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100">
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <input v-model="form.is_public" type="checkbox" class="rounded border-stone-300 text-emerald-700 focus:ring-emerald-700">
                            Page publique activable
                        </label>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-stone-950 dark:text-neutral-50">Elements inclus</h3>
                                <button type="button" class="text-sm font-medium text-emerald-700 hover:text-emerald-900 dark:text-emerald-300" @click="addItem">
                                    Ajouter
                                </button>
                            </div>

                            <div
                                v-for="(item, index) in form.items"
                                :key="index"
                                class="space-y-2 border border-stone-200 p-3 dark:border-neutral-800"
                            >
                                <select v-model="item.product_id" class="w-full rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100" @change="updateItemPrice(item)">
                                    <option v-for="catalogItemOption in catalogItems" :key="catalogItemOption.id" :value="catalogItemOption.id">
                                        {{ catalogItemOption.name }} - {{ catalogItemOption.item_type }}
                                    </option>
                                </select>
                                <div class="grid grid-cols-[1fr_1fr_auto] gap-2">
                                    <input v-model="item.quantity" type="number" min="0.01" step="0.01" class="rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100" placeholder="Quantite">
                                    <input v-model="item.unit_price" type="number" min="0" step="0.01" class="rounded-sm border-stone-300 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100" placeholder="Prix">
                                    <button type="button" class="rounded-sm border border-stone-300 px-3 text-sm text-stone-700 disabled:opacity-40 dark:border-neutral-700 dark:text-neutral-200" :disabled="form.items.length === 1" @click="removeItem(index)">
                                        Retirer
                                    </button>
                                </div>
                            </div>
                            <p v-if="form.errors.items" class="text-xs text-rose-600">{{ form.errors.items }}</p>
                        </div>

                        <button
                            type="submit"
                            class="w-full rounded-sm bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ isEditing ? 'Mettre a jour' : 'Creer l offre' }}
                        </button>
                    </form>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
