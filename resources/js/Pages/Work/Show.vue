<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StarRating from '@/Components/UI/StarRating.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    work: Object,
    customer: Object,
    lockedFromQuote: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const companyName = computed(() => page.props.auth?.account?.company?.name || 'Entreprise');
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);

const ratingValue = computed(() => {
    const ratings = props.work?.ratings || [];
    if (!ratings.length) {
        return null;
    }
    const sum = ratings.reduce((total, rating) => total + Number(rating.rating || 0), 0);
    return sum / ratings.length;
});

const ratingCount = computed(() => props.work?.ratings?.length || 0);

const statusLabels = {
    to_schedule: 'A planifier',
    scheduled: 'Planifie',
    en_route: 'En route',
    in_progress: 'En cours',
    tech_complete: 'Tech termine',
    pending_review: 'En attente de validation',
    validated: 'Valide',
    auto_validated: 'Auto valide',
    dispute: 'Litige',
    closed: 'Cloture',
    cancelled: 'Annule',
    completed: 'Termine (ancien)',
};

const formatStatus = (status) => statusLabels[status] || status || '-';

const isLocked = computed(() =>
    ['validated', 'auto_validated', 'closed', 'completed'].includes(props.work?.status || '')
);

const createInvoice = () => {
    router.post(route('invoice.store-from-work', props.work.id), {}, { preserveScroll: true });
};
</script>
<template>

    <Head title="Voir le job" />
    <AuthenticatedLayout>
        <div class="max-w-5xl mx-auto space-y-4">
            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <img v-if="companyLogo"
                            :src="companyLogo"
                            :alt="companyName"
                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700" />
                        <div>
                            <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                {{ companyName }}
                            </p>
                            <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                {{ work.job_title }}
                            </h1>
                            <p class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ work.number }} - {{ formatStatus(work.status) }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link v-if="!isLocked" :href="route('work.edit', work.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                            Modifier le job
                        </Link>
                        <span v-else class="text-xs text-stone-500 dark:text-neutral-400">
                            Job verrouille apres validation.
                        </span>
                        <Link :href="route('work.proofs', work.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                            Preuves
                        </Link>
                        <Link v-if="work.invoice" :href="route('invoice.show', work.invoice.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                            Voir la facture
                        </Link>
                        <button v-else type="button" @click="createInvoice"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            Creer une facture
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Client</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">
                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                    </p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ customer.email }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Dates</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">Debut : {{ work.start_date || '-' }}</p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Fin : {{ work.end_date || '-' }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Totaux</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">Sous-total : ${{ Number(work.subtotal || 0).toFixed(2) }}</p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Total : ${{ Number(work.total || 0).toFixed(2) }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Note</h2>
                    <div class="mt-1 flex items-center gap-2">
                        <StarRating :value="ratingValue" show-value empty-label="Aucune note" />
                        <span v-if="ratingCount" class="text-xs text-stone-500 dark:text-neutral-400">
                            ({{ ratingCount }})
                        </span>
                    </div>
                </div>
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div v-if="lockedFromQuote" class="mb-3 text-xs text-amber-600">
                    Ce job est verrouille car il provient d'un devis accepte.
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-start text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Produit/Service
                                </th>
                                <th class="px-4 py-3 text-start text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Qte
                                </th>
                                <th class="px-4 py-3 text-start text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Prix unitaire
                                </th>
                                <th class="px-4 py-3 text-start text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="product in work.products || []" :key="product.id">
                                <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ product.name }}
                                </td>
                                <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ product.pivot?.quantity ?? 0 }}
                                </td>
                                <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ Number(product.pivot?.price ?? 0).toFixed(2) }}
                                </td>
                                <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ Number(product.pivot?.total ?? 0).toFixed(2) }}
                                </td>
                            </tr>
                            <tr v-if="!work.products || work.products.length === 0">
                                <td colspan="4" class="px-4 py-3 text-sm text-stone-500 dark:text-neutral-400">
                                    Aucun produit/service associe.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
