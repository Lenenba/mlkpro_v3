<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StarRating from '@/Components/UI/StarRating.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    work: Object,
    customer: Object,
    lockedFromQuote: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const { t } = useI18n();
const companyName = computed(() => page.props.auth?.account?.company?.name || t('jobs.company_fallback'));
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

const statusLabels = computed(() => ({
    to_schedule: t('jobs.status.to_schedule'),
    scheduled: t('jobs.status.scheduled'),
    en_route: t('jobs.status.en_route'),
    in_progress: t('jobs.status.in_progress'),
    tech_complete: t('jobs.status.tech_complete'),
    pending_review: t('jobs.status.pending_review'),
    validated: t('jobs.status.validated'),
    auto_validated: t('jobs.status.auto_validated'),
    dispute: t('jobs.status.dispute'),
    closed: t('jobs.status.closed'),
    cancelled: t('jobs.status.cancelled'),
    completed: t('jobs.status.completed'),
}));

const formatStatus = (status) => statusLabels.value[status] || status || '-';

const isLocked = computed(() =>
    ['validated', 'auto_validated', 'closed', 'completed'].includes(props.work?.status || '')
);

const dispatchDemoEvent = (eventName) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent(eventName));
};

const createInvoice = () => {
    router.post(route('invoice.store-from-work', props.work.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            dispatchDemoEvent('demo:invoice_created');
        },
    });
};
</script>
<template>

    <Head :title="$t('jobs.show_title')" />
    <AuthenticatedLayout>
        <div class="max-w-5xl mx-auto space-y-4 rise-stagger">
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
                        <Link
                            :href="route('pipeline.timeline', { entityType: 'job', entityId: work.id })"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 action-feedback"
                        >
                            {{ $t('jobs.show.timeline') }}
                        </Link>
                        <Link v-if="!isLocked" :href="route('work.edit', work.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 action-feedback">
                            {{ $t('jobs.show.edit_job') }}
                        </Link>
                        <span v-else class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('jobs.show.locked_notice') }}
                        </span>
                        <Link :href="route('work.proofs', work.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 action-feedback">
                            {{ $t('jobs.show.proofs') }}
                        </Link>
                        <Link v-if="work.invoice" :href="route('invoice.show', work.invoice.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 action-feedback">
                            {{ $t('jobs.show.view_invoice') }}
                        </Link>
                        <button v-else type="button" @click="createInvoice" data-testid="demo-create-invoice"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 action-feedback">
                            {{ $t('jobs.show.create_invoice') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('jobs.show.cards.customer') }}</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">
                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                    </p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ customer.email }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('jobs.show.cards.dates') }}</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">{{ $t('jobs.show.cards.start') }}: {{ work.start_date || '-' }}</p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('jobs.show.cards.end') }}: {{ work.end_date || '-' }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('jobs.show.cards.totals') }}</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">{{ $t('jobs.show.cards.subtotal') }}: ${{ Number(work.subtotal || 0).toFixed(2) }}</p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('jobs.show.cards.total') }}: ${{ Number(work.total || 0).toFixed(2) }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('jobs.show.cards.rating') }}</h2>
                    <div class="mt-1 flex items-center gap-2">
                        <StarRating :value="ratingValue" show-value :empty-label="$t('jobs.show.no_rating')" />
                        <span v-if="ratingCount" class="text-xs text-stone-500 dark:text-neutral-400">
                            ({{ ratingCount }})
                        </span>
                    </div>
                </div>
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div v-if="lockedFromQuote" class="mb-3 text-xs text-amber-600">
                    {{ $t('jobs.show.locked_from_quote') }}
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-start text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    {{ $t('jobs.show.table.product_service') }}
                                </th>
                                <th class="px-4 py-3 text-start text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    {{ $t('jobs.show.table.qty') }}
                                </th>
                                <th class="px-4 py-3 text-start text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    {{ $t('jobs.show.table.unit_price') }}
                                </th>
                                <th class="px-4 py-3 text-start text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    {{ $t('jobs.show.table.total') }}
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
                                    {{ $t('jobs.show.table.empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
