<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    customers: {
        type: Array,
        default: () => [],
    },
    products: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    customer_id: '',
    status: 'draft',
    notes: '',
    items: [
        {
            product_id: '',
            quantity: 1,
            price: '',
            description: '',
        },
    ],
});

const productMap = computed(() => {
    const map = {};
    props.products.forEach((product) => {
        map[product.id] = product;
    });
    return map;
});

const parseNumber = (value) => {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
};

const applyProductDefaults = (index) => {
    const item = form.items[index];
    if (!item) {
        return;
    }
    const product = productMap.value[item.product_id];
    if (!product) {
        return;
    }
    if (!item.price) {
        item.price = product.price;
    }
    if (!item.description) {
        item.description = product.name;
    }
};

const addItem = () => {
    form.items.push({
        product_id: '',
        quantity: 1,
        price: '',
        description: '',
    });
};

const removeItem = (index) => {
    form.items.splice(index, 1);
    if (!form.items.length) {
        addItem();
    }
};

const subtotal = computed(() =>
    form.items.reduce((total, item) => {
        const quantity = parseNumber(item.quantity);
        const price = parseNumber(item.price);
        return total + quantity * price;
    }, 0)
);

const taxTotal = computed(() =>
    form.items.reduce((total, item) => {
        const product = productMap.value[item.product_id];
        const taxRate = parseNumber(product?.tax_rate);
        if (!taxRate) {
            return total;
        }
        const lineTotal = parseNumber(item.quantity) * parseNumber(item.price);
        return total + (lineTotal * taxRate) / 100;
    }, 0)
);

const total = computed(() => subtotal.value + taxTotal.value);

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const submit = () => {
    form.post(route('sales.store'));
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Nouvelle vente" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Nouvelle vente</h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        Creez une commande rapide avec vos produits.
                    </p>
                </div>
                <Link
                    :href="route('sales.index')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    Retour aux ventes
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div>
                            <label class="text-xs text-stone-500 dark:text-neutral-400">Client</label>
                            <select
                                v-model.number="form.customer_id"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            >
                                <option value="">Selectionner un client</option>
                                <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                    {{ customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() || customer.email }}
                                </option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.customer_id" />
                        </div>
                        <div>
                            <label class="text-xs text-stone-500 dark:text-neutral-400">Statut</label>
                            <select
                                v-model="form.status"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            >
                                <option value="draft">Brouillon</option>
                                <option value="pending">En attente</option>
                                <option value="paid">Payee</option>
                                <option value="canceled">Annulee</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.status" />
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Lignes produits</h2>
                        <button
                            type="button"
                            class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="addItem"
                        >
                            + Ajouter
                        </button>
                    </div>

                    <div class="mt-3 space-y-3">
                        <div
                            v-for="(item, index) in form.items"
                            :key="index"
                            class="grid grid-cols-1 gap-3 rounded-sm border border-stone-200 p-3 dark:border-neutral-700 md:grid-cols-[2fr_1fr_1fr_2fr_auto]"
                        >
                            <div>
                                <label class="text-xs text-stone-500 dark:text-neutral-400">Produit</label>
                                <select
                                    v-model.number="item.product_id"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    @change="applyProductDefaults(index)"
                                >
                                    <option value="">Selectionner un produit</option>
                                    <option v-for="product in products" :key="product.id" :value="product.id">
                                        {{ product.name }} (stock: {{ product.stock }})
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors[`items.${index}.product_id`]" />
                            </div>
                            <div>
                                <label class="text-xs text-stone-500 dark:text-neutral-400">Quantite</label>
                                <input
                                    v-model.number="item.quantity"
                                    type="number"
                                    min="1"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                />
                                <InputError class="mt-1" :message="form.errors[`items.${index}.quantity`]" />
                            </div>
                            <div>
                                <label class="text-xs text-stone-500 dark:text-neutral-400">Prix</label>
                                <input
                                    v-model.number="item.price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                />
                                <InputError class="mt-1" :message="form.errors[`items.${index}.price`]" />
                            </div>
                            <div>
                                <label class="text-xs text-stone-500 dark:text-neutral-400">Description</label>
                                <input
                                    v-model="item.description"
                                    type="text"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                />
                                <InputError class="mt-1" :message="form.errors[`items.${index}.description`]" />
                            </div>
                            <div class="flex items-end justify-end">
                                <button
                                    type="button"
                                    class="rounded-sm border border-red-200 bg-white px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-900/50 dark:bg-neutral-900 dark:text-red-300 dark:hover:bg-red-900/20"
                                    @click="removeItem(index)"
                                >
                                    Retirer
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <label class="text-xs text-stone-500 dark:text-neutral-400">Notes (optionnel)</label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    />
                    <InputError class="mt-1" :message="form.errors.notes" />
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <div class="flex items-center justify-between">
                            <span>Sous-total</span>
                            <span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Taxes</span>
                            <span class="font-medium">{{ formatCurrency(taxTotal) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                            <span class="font-semibold">Total</span>
                            <span class="font-semibold">{{ formatCurrency(total) }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                    >
                        Enregistrer la vente
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
